<?php

namespace App\Services\Notice;

use App\Exceptions\ApiException;
use App\Services\HwAssignment\HomeworkTime;
use App\Services\SchoolDbService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes of the notice API: publishing (5.4) and read marks (5.2). Only app_notice and app_notice_read
 * are written, through the notice write connection. No push notification is sent yet.
 */
class NoticeService
{
    public const CATEGORIES = [
        'general', 'academic', 'examination', 'class_routine', 'homework', 'fee_payment', 'holiday', 'transport',
        'library', 'result_achievement', 'parent_notice', 'teacher_notice', 'event_program', 'emergency',
        'admission', 'routine_change', 'circular', 'other', 'custom',
    ];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public const AUDIENCE_ROLES = ['students', 'guardians', 'teachers', 'staff'];

    private const FILE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];

    private const MAX_FILE_KB = 5120;

    public function __construct(
        private SchoolDbService $schools,
        private NoticeReader $reader,
        private NoticeFiles $files,
    ) {}

    /**
     * 5.4 Publishes a notice. Returns [id, created]; created is false when the Idempotency-Key was
     * already used, and the first notice's id is returned.
     */
    public function publish(NoticeCaller $caller, Request $request): array
    {
        $input = $this->validate($caller, $request);
        $key = $request->header('Idempotency-Key') ?: null;
        $db = $this->schools->connectForNotice($caller->dbName);

        if ($key && ($existing = $db->table('app_notice')->where('idempotency_key', $key)->value('id'))) {
            return [(int) $existing, false];
        }

        // A teacher may only target classes they teach (contract 5.4: otherwise 403).
        if (! $input['audience_classes'] || array_diff($input['audience_classes'], array_keys($caller->teacherClasses))) {
            throw new ApiException('You can only send notices to your own classes', 403);
        }

        $stored = $this->files->store($caller->db, $input['files'], 'ntc_'.$caller->teacherCode);
        $today = HomeworkTime::today();

        try {
            $id = $db->table('app_notice')->insertGetId([
                'category' => $input['category'],
                'category_label' => $input['category'] === 'custom' ? $input['category_label'] : null,
                'priority' => $input['priority'],
                'title' => $input['title'],
                'body' => $input['body'],
                'audience_roles' => implode(',', $input['audience_roles']),
                'audience_classes' => implode(',', $input['audience_classes']),
                'audience_sections' => implode(',', $input['audience_sections']),
                'audience_groups' => implode(',', $input['audience_groups']),
                'published_at' => ($input['publish_on'] ?? $today) === $today ? HomeworkTime::now() : $input['publish_on'].' 00:00:00',
                'expires_at' => isset($input['expires_on']) ? $input['expires_on'].' 23:59:59' : null,
                'attachments' => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'request_data' => json_encode(array_diff_key($input, ['files' => true]) + ['files' => array_column($stored, 'name')], JSON_UNESCAPED_UNICODE),
                'sender_type' => 'teacher',
                'sender_id' => (int) $caller->teacherCode,
                'app_user_id' => $caller->user->id,
                'idempotency_key' => $key,
                'status' => 1,
                'created_at' => HomeworkTime::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two retries arrived at the same moment; the first one is the notice.
            $this->files->delete(array_column($stored, 'path'));

            return [(int) $db->table('app_notice')->where('idempotency_key', $key)->value('id'), false];
        } catch (\Throwable $e) {
            $this->files->delete(array_column($stored, 'path'));

            throw $e;
        }

        // TODO: push notification to the audience, once push is set up.

        return [(int) $id, true];
    }

    /**
     * 5.2 Marks a notice read for the caller. A repeat is harmless.
     */
    public function markRead(NoticeCaller $caller, string $noticeId): void
    {
        [$source, $id] = str_starts_with($noticeId, 'web-') ? ['web', substr($noticeId, 4)] : ['app', $noticeId];

        if (! ctype_digit($id) || ! $caller->readerId || ! $this->reader->exists($caller->db, $source, (int) $id)) {
            throw new ApiException('Notice not found', 404);
        }

        $this->schools->connectForNotice($caller->dbName)->table('app_notice_read')->insertOrIgnore([
            'source' => $source,
            'notice_id' => (int) $id,
            'reader_type' => $caller->readerType,
            'reader_id' => $caller->readerId,
            'read_at' => HomeworkTime::now(),
        ]);
    }

    /**
     * The 5.4 form, with list fields accepted as name[] or one comma-separated value.
     */
    private function validate(NoticeCaller $caller, Request $request): array
    {
        $lists = [];
        foreach (['audience_roles', 'audience_classes', 'audience_sections', 'audience_groups'] as $field) {
            $value = $request->input($field);
            $lists[$field] = array_values(array_unique(array_filter(array_map('trim', is_array($value) ? $value : explode(',', (string) $value)), 'strlen')));
        }

        $today = HomeworkTime::today();

        $input = Validator::make($lists + $request->only(['category', 'category_label', 'priority', 'title', 'body', 'publish_on', 'expires_on']) + ['files' => array_values(array_filter((array) $request->file('files')))], [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'category_label' => ['required_if:category,custom', 'nullable', 'string', 'max:100'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'audience_roles' => ['required', 'array', 'min:1'],
            'audience_roles.*' => [Rule::in(self::AUDIENCE_ROLES)],
            'audience_classes' => ['array'],
            'audience_classes.*' => ['integer'],
            'audience_sections' => ['array'],
            'audience_sections.*' => ['integer'],
            'audience_groups' => ['array'],
            'audience_groups.*' => ['integer'],
            'publish_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:'.($request->input('publish_on') ?: $today)],
            'files' => ['array'],
            'files.*' => ['file', 'max:'.self::MAX_FILE_KB, 'mimes:'.implode(',', self::FILE_TYPES)],
        ])->validate();

        foreach (['audience_classes' => ['class_info', 'class_code'], 'audience_sections' => ['section_info', 'section_code'], 'audience_groups' => ['group_info', 'group_code']] as $field => [$table, $column]) {
            $codes = array_map('intval', $input[$field] ?? []);
            $input[$field] = $codes;

            if (array_diff($codes, $this->reader->existingCodes($caller->db, $table, $column, $codes))) {
                throw ValidationException::withMessages([$field => 'Unknown id.']);
            }
        }

        return $input + ['files' => []];
    }
}
