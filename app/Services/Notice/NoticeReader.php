<?php

namespace App\Services\Notice;

use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * Read queries and the JSON shapes of the notice API.
 *
 * The feed has two sources:
 *  - app_notice: notices published from the app, with an audience: roles plus optional classes /
 *    sections / groups (comma lists; empty = all). "The server works out which notices this user
 *    should see" (contract 5.1).
 *  - notice: the school website's public notices (type 1, showDocument 1), shown to everyone. Read only.
 * app_notice_read keeps who has read what, for both sources.
 */
class NoticeReader
{
    public function __construct(private NoticeFiles $files) {}

    /**
     * 5.1 The caller's notices, newest first. Future publish dates are left out; expired ones stay.
     */
    public function feed(NoticeCaller $caller): array
    {
        $url = $this->files->urlResolver($caller->db);
        $notices = [];

        foreach ($this->appNotices($caller) as $row) {
            $notices[] = $this->item((string) $row->id, $row->title, $row->body, $row->category, $row->category_label, $row->priority, $row->published_at, $row->expires_at, $row->read_id !== null, array_map(
                fn ($file) => ['name' => $file['name'], 'url' => $url($file['path']), 'size_bytes' => $file['size_bytes']],
                json_decode((string) $row->attachments, true) ?: [],
            ));
        }

        $institute = $caller->db->table('institute_info')->first(['institute_EIIN', 'institute_storage_path']);

        foreach ($this->webNotices($caller) as $row) {
            $attachments = [];

            if (trim((string) $row->attachment) !== '' && $institute) {
                $file = $row->id.$row->attachment;
                $attachments[] = [
                    'name' => 'notice-'.$file,
                    'url' => rtrim((string) $institute->institute_storage_path, '/').'/'.$institute->institute_EIIN.'/site/documents/'.$file,
                    'size_bytes' => null,
                ];
            }

            $title = trim((string) $row->title_english) !== '' ? $row->title_english : $row->title_bangla;
            $body = trim(strip_tags((string) $row->des_english)) !== '' ? $row->des_english : $row->des_bangla;

            $notices[] = $this->item('web-'.$row->id, $title, $this->plainText($body), 'general', null, 'normal', $row->notice_publish_date_time, null, $row->read_id !== null, $attachments);
        }

        // Newest first across both sources. The app re-sorts urgent first.
        usort($notices, fn ($a, $b) => strcmp((string) $b['published_at'], (string) $a['published_at']));

        return $notices;
    }

    /**
     * 5.3 What the sender may target: their own classes (with sections), the groups, and the custom
     * categories already used.
     */
    public function audienceOptions(NoticeCaller $caller): array
    {
        $sections = $caller->db->table('student_current_status_info as s')
            ->join('session_info as session', function ($join) {
                $join->on('session.session_code', '=', 's.session_code')->where('session.running_session', 1);
            })
            ->join('section_info as section', function ($join) {
                $join->on('section.section_code', '=', 's.section_code')->where('section.section_activation_status', 1);
            })
            ->where('s.active_status', 1)
            ->distinct()
            ->orderBy('section.sec_position')
            ->orderBy('section.section_code')
            ->get(['s.class_code', 'section.section_code', 'section.section_name'])
            ->groupBy('class_code');

        $classes = [];

        foreach ($caller->db->table('class_info')->where('class_activation_status', 1)->orderBy('class_position')->orderBy('class_code')->get(['class_code', 'class_name']) as $class) {
            $code = (int) $class->class_code;

            if (! isset($caller->teacherClasses[$code])) {
                continue;
            }

            // A section of 0 in an assignment means every section.
            $allowed = in_array(0, $caller->teacherClasses[$code], true) ? null : $caller->teacherClasses[$code];

            $classes[] = [
                'id' => (string) $code,
                'name' => $class->class_name,
                'sections' => ($sections[$code] ?? collect())
                    ->filter(fn ($section) => $allowed === null || in_array((int) $section->section_code, $allowed, true))
                    ->map(fn ($section) => ['id' => (string) $section->section_code, 'name' => $section->section_name])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'classes' => $classes,
            'groups' => $caller->db->table('group_info')->where('group_active_status', 1)->orderBy('group_code')->get(['group_code', 'group_name'])
                ->map(fn ($group) => ['id' => (string) $group->group_code, 'name' => $group->group_name])
                ->all(),
            'custom_categories' => $caller->db->table('app_notice')
                ->where('category', 'custom')
                ->where('status', 1)
                ->where('category_label', '<>', '')
                ->distinct()
                ->orderBy('category_label')
                ->pluck('category_label')
                ->all(),
        ];
    }

    /**
     * True when the notice exists for read marks: "12" is an app notice, "web-22" a website notice.
     */
    public function exists(Connection $db, string $source, int $id): bool
    {
        return $source === 'web'
            ? $db->table('notice')->where('id', $id)->where('type', 1)->where('showDocument', 1)->exists()
            : $db->table('app_notice')->where('id', $id)->where('status', 1)->exists();
    }

    /**
     * The codes that exist in a lookup table, from a list (to check audience filters).
     */
    public function existingCodes(Connection $db, string $table, string $column, array $codes): array
    {
        return $codes ? $db->table($table)->whereIn($column, $codes)->pluck($column)->map(fn ($code) => (int) $code)->all() : [];
    }

    private function appNotices(NoticeCaller $caller): array
    {
        $query = $caller->db->table('app_notice as n')
            ->leftJoin('app_notice_read as r', function ($join) use ($caller) {
                $join->on('r.notice_id', '=', 'n.id')
                    ->where('r.source', 'app')
                    ->where('r.reader_type', $caller->readerType)
                    ->where('r.reader_id', (int) $caller->readerId);
            })
            ->where('n.status', 1)
            ->where('n.published_at', '<=', HomeworkTime::now())
            ->whereRaw('FIND_IN_SET(?, n.audience_roles)', [$caller->audienceRole]);

        if ($caller->isTeacher()) {
            // A notice for some classes reaches the teachers of those classes.
            $query->where(function (Builder $q) use ($caller) {
                $q->where('n.audience_classes', '');

                foreach (array_keys($caller->teacherClasses) as $class) {
                    $q->orWhereRaw('FIND_IN_SET(?, n.audience_classes)', [$class]);
                }
            });
        } elseif ($caller->placement) {
            foreach (['audience_classes' => 'class_code', 'audience_sections' => 'section_code', 'audience_groups' => 'group_code'] as $column => $field) {
                $query->where(fn (Builder $q) => $q->where("n.$column", '')->orWhereRaw("FIND_IN_SET(?, n.$column)", [(int) $caller->placement->$field]));
            }
        } else {
            // A student with no current class gets only whole-school notices.
            $query->where('n.audience_classes', '')->where('n.audience_sections', '')->where('n.audience_groups', '');
        }

        return $query->orderByDesc('n.published_at')->orderByDesc('n.id')
            ->get(['n.id', 'n.category', 'n.category_label', 'n.priority', 'n.title', 'n.body', 'n.published_at', 'n.expires_at', 'n.attachments', 'r.id as read_id'])
            ->all();
    }

    private function webNotices(NoticeCaller $caller): array
    {
        return $caller->db->table('notice as w')
            ->leftJoin('app_notice_read as r', function ($join) use ($caller) {
                $join->on('r.notice_id', '=', 'w.id')
                    ->where('r.source', 'web')
                    ->where('r.reader_type', $caller->readerType)
                    ->where('r.reader_id', (int) $caller->readerId);
            })
            ->where('w.type', 1)
            ->where('w.showDocument', 1)
            ->where('w.notice_publish_date_time', '<=', HomeworkTime::now())
            ->orderByDesc('w.notice_publish_date_time')
            ->get(['w.id', 'w.title_english', 'w.title_bangla', 'w.des_english', 'w.des_bangla', 'w.attachment', 'w.notice_publish_date_time', 'r.id as read_id'])
            ->all();
    }

    /**
     * One feed item. student_id and the names are the guardian's child; this app has no guardian
     * login yet, so they are always null.
     */
    private function item(string $id, ?string $title, ?string $body, string $category, ?string $label, string $priority, ?string $publishedAt, ?string $expiresAt, bool $isRead, array $attachments): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'body' => $body,
            'category' => $category,
            'category_label' => $label,
            'priority' => $priority,
            'published_at' => HomeworkTime::toApi($publishedAt),
            'expires_at' => HomeworkTime::toApi($expiresAt),
            'is_read' => $isRead,
            'attachments' => $attachments,
            'student_id' => null,
            'student_name_bn' => null,
            'student_name_en' => null,
        ];
    }

    // Website notice HTML as plain text.
    private function plainText(?string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/p>/i', "\n", (string) $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
