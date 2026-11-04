# Notices API v1 (Module 5)

These are the notice endpoints for the mobile app. They follow *PATHSHALA Apps — API Contract v1, Module 5* (`PATHSHALA_API_v1.pdf`), and they are built the same way as the homework API. They use this project's envelope, `{ status, message, data }`.

The feed has two sources:

- notices published from the app, stored in `app_notice`;
- the school website's public notices, from the web table `notice`. These are only read.

Read marks are kept in `app_notice_read`.

| File | What it holds |
|---|---|
| `app/Http/Controllers/Notice/NoticeController.php` | 5.1 feed, 5.2 mark read, 5.4 publish |
| `app/Http/Controllers/Notice/NoticeAudienceController.php` | 5.3 audience options |
| `app/Services/Notice/NoticeReader.php` | Feed queries (both sources), audience matching, audience options |
| `app/Services/Notice/NoticeService.php` | Publish validation and insert, read marks, through `tenant_notice` |
| `app/Services/Notice/NoticeFiles.php` | Attachment storage and URLs |
| `app/Services/Notice/NoticeCaller.php` | Who is calling: reader type and id, audience role, placement, the teacher's classes |
| `app/Http/Middleware/AuthorizeNotice.php` | `notice` (both roles), `notice:teacher` |
| `tests/Feature/NoticeApiTest.php` | Feature tests |
| `postman/Pathshala_Notice_API.postman_collection.json` | Login, then all four endpoints |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://127.0.0.1:8000/api/v1` |
| Live | `<api host>/api/v1` |

Every request needs `Authorization: Bearer <token>`. The token decides the school and the caller.

| Caller | Reader type and id (for read marks) | Audience role | May use |
|---|---|---|---|
| Student (verified) | `student`, `student_registration_code` | `students` | 5.1, 5.2 |
| Teacher (verified, active login) | `teacher`, `teacher_code` | `teachers` | 5.1–5.4 |
| Staff (a teacher login whose `teacher_info.employee_type = 2`) | `staff`, `teacher_code` | `staff` | 5.1–5.4 |
| Guest, or a student on 5.3 / 5.4 | | | 403 |

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 5.1 | GET | `/notices` | The caller's notices, newest first |
| 5.2 | POST | `/notices/{noticeId}/read` | Mark one notice as read |
| 5.3 | GET | `/notices/audience-options` | What a teacher may target |
| 5.4 | POST | `/notices` | Publish a notice (multipart) |

Notice ids are strings:

- `"12"` is an app notice (`app_notice.id`);
- `"web-22"` is a website notice (`notice.id`).

5.2 accepts both forms.

### 5.1 response (`data`)

```json
[
  {
    "id": "12",
    "title": "Sports day",
    "body": "Sports day is on Thursday.",
    "category": "event_program",
    "category_label": null,
    "priority": "high",
    "published_at": "2026-11-02T04:00:00Z",
    "expires_at": "2026-11-10T17:59:59Z",
    "is_read": false,
    "attachments": [ { "name": "schedule.pdf", "url": "http://.../storage/<EIIN>/app/notices/ntc_7_....pdf", "size_bytes": 20480 } ],
    "student_id": null,
    "student_name_bn": null,
    "student_name_en": null
  },
  { "id": "web-22", "title": "Admission notice", "category": "general", "priority": "normal", "expires_at": null, "...": "..." }
]
```

Times are UTC; the database stores Dhaka time.

### 5.3 response (`data`)

```json
{
  "classes": [ { "id": "5", "name": "Six", "sections": [ { "id": "1", "name": "A" } ] } ],
  "groups": [ { "id": "1", "name": "Science" } ],
  "custom_categories": [ "Picnic" ]
}
```

### 5.4 request (multipart/form-data)

| Field | Rule |
|---|---|
| `category` | Required. One of `general`, `academic`, `examination`, `class_routine`, `homework`, `fee_payment`, `holiday`, `transport`, `library`, `result_achievement`, `parent_notice`, `teacher_notice`, `event_program`, `emergency`, `admission`, `routine_change`, `circular`, `other`, `custom` |
| `category_label` | Required when `category = custom`, at most 100 characters. Otherwise it is ignored |
| `priority` | Required. One of `urgent`, `high`, `normal`, `low` |
| `title` | Required, at most 255 characters |
| `body` | Required |
| `audience_roles` | Required, at least one of `students`, `guardians`, `teachers`, `staff` |
| `audience_classes` | Class codes. They must be classes the teacher teaches, and at least one is needed (see §4) |
| `audience_sections`, `audience_groups` | Optional section codes and group codes. Empty means all |
| `publish_on` | Optional `Y-m-d`, today or later. Today, or no value, means now |
| `expires_on` | Optional `Y-m-d`, after `publish_on` (or after today). The notice expires at 23:59:59 that day |
| `files[]` | Optional. Each file at most 5 MB, of type jpg, jpeg, png, gif, pdf, doc, docx, xls, xlsx, ppt, pptx or txt |

The list fields can be sent as `name[]` or as one comma-separated value, for example `audience_classes=5,6`.

The `Idempotency-Key` header is optional. The answers are:

- a new notice: 201 "Notice published" with `{ "id": "12" }`;
- the same key again: 200 "Notice already published", with the first id and the header `Idempotent-Replayed: true`.

## 3. How the contract maps to the database

| Contract | App notice (`app_notice`) | Website notice (`notice`) |
|---|---|---|
| `id` | `id` | `"web-" + id` |
| `title` | `title` | `title_english`, else `title_bangla` |
| `body` | `body` | `des_english`, else `des_bangla`, with the HTML turned into plain text |
| `category`, `priority` | Stored values | Always `general`, `normal` |
| `category_label` | Only for `custom` | `null` |
| `published_at` | `published_at` | `notice_publish_date_time` |
| `expires_at` | `expires_at` | `null` |
| `attachments` | JSON in `attachments` with each file's URL | A file named `<id><attachment>` under `institute_storage_path/<EIIN>/site/documents/`, `size_bytes` `null` |
| `is_read` | A row in `app_notice_read` for this reader | The same, with `source = web` |
| `student_id`, `student_name_bn`, `student_name_en` | `null`: these are for a guardian's child, and there is no guardian login yet | `null` |

## 4. Rules

### Feed (5.1)

- **Sources:**
  - active app notices (`status = 1`);
  - website notices with `type = 1` and `showDocument = 1`.
- **Order:** future publish dates are hidden; expired notices are kept. The feed is newest first across both sources, and the app re-sorts it with urgent first.
- **Audience for a student:**
  - the role list must include `students`;
  - the notice's classes, sections and groups must each be empty or include the student's current class, section and group. The current placement is the active row in a running session, the highest session first;
  - a student with no current placement only gets notices for the whole school.
- **Audience for a teacher or staff member:**
  - the role list must include `teachers` (or `staff`);
  - the classes must be empty or include one of the classes the teacher teaches;
  - so a notice for some classes also reaches the teachers of those classes.
- Website notices are shown to everyone.

### Mark read (5.2)

- One row per notice and reader (`uq_read`). A repeat does nothing and still answers 200.
- An unknown, removed or hidden notice is 404.
- Read marks are not written to the activity log.

### Audience options (5.3)

- **Classes:** the classes the teacher teaches, as class teacher or subject teacher, in running sessions.
- **Sections:** the active sections that have active students in a running session. If the teacher's assignment names sections, only those are listed; a section of 0 means all sections.
- **Groups:** every active group.
- **Custom categories:** the `category_label`s already used on custom notices.

### Publish (5.4)

- Every class in `audience_classes` must be one the teacher teaches. If the list is empty, or has another class, the answer is 403 "You can only send notices to your own classes". Unknown class, section or group codes are 422.
- **Files:**
  - they are stored like homework files, on the disk in `HW_FILES_DISK`, at `<EIIN>/app/notices/ntc_<teacher_code>_<time>_<random>_<n>.<ext>`;
  - a file on that disk gets its URL from `HW_FILES_URL` (or `/storage`). Otherwise the URL is the school's `institute_storage_path`;
  - if the insert fails, the files are deleted again.
- The form is also stored as JSON in `request_data`, with the file names but not the files. `sender_type` is `teacher`, and `sender_id` is the `teacher_code`.
- It is logged as `activity:publish_notice`, with `noticeId`.
- **No push notification is sent yet.** This is marked TODO in `NoticeService::publish`.

## 5. Tables (run on every school database)

The write connection is `tenant_notice`, and it writes only to these two tables. The web `notice` table is only read.

```sql
CREATE TABLE IF NOT EXISTS `app_notice` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(30) NOT NULL,
  `category_label` varchar(100) DEFAULT NULL COMMENT 'name of a custom category',
  `priority` varchar(10) NOT NULL COMMENT 'urgent, high, normal, low',
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `audience_roles` varchar(100) NOT NULL COMMENT 'comma list: students,guardians,teachers,staff',
  `audience_classes` varchar(255) NOT NULL DEFAULT '' COMMENT 'comma list of class_code; empty = whole school',
  `audience_sections` varchar(255) NOT NULL DEFAULT '' COMMENT 'comma list of section_code; empty = all',
  `audience_groups` varchar(255) NOT NULL DEFAULT '' COMMENT 'comma list of group_code; empty = all',
  `published_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `attachments` longtext COMMENT 'JSON: [{name, path, size_bytes}]',
  `request_data` longtext NOT NULL COMMENT 'form fields from the app, as JSON',
  `sender_type` varchar(10) NOT NULL COMMENT 'teacher or admin',
  `sender_id` int(11) NOT NULL COMMENT 'teacher_code or users.id',
  `app_user_id` int(11) DEFAULT NULL COMMENT 'app_user.id of the sender (master DB)',
  `idempotency_key` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 active, 0 removed',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency_key` (`idempotency_key`),
  KEY `idx_published` (`status`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `app_notice_read` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(5) NOT NULL COMMENT 'app = app_notice, web = notice',
  `notice_id` int(11) NOT NULL,
  `reader_type` varchar(10) NOT NULL COMMENT 'student, guardian, teacher, staff',
  `reader_id` int(11) NOT NULL COMMENT 'student_registration_code or teacher_code',
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_read` (`source`,`notice_id`,`reader_type`,`reader_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

## 6. Errors

Errors use the envelope `{ "status": false, "message": "...", "errors"?: { field: [..] } }`.

| HTTP | Cases |
|---|---|
| 401 | No token, a bad token, or a token that was logged out |
| 403 | A student on 5.3 / 5.4; a class the teacher does not teach, or no class (5.4); a guest; a banned teacher |
| 404 | An unknown, removed or hidden notice (5.2); a bad id format |
| 422 | Any 5.4 field rule above, or an unknown class / section / group code (`errors.<field>`) |
| 429 | More than 120 requests a minute from one IP (`throttle:notice`) |

## 7. Tested on local (2026-11-03)

`NoticeApiTest` runs on `pathshala_app_test_school`, with explicit ids, because the test school's `notice` table has no auto-increment. It covers:

- website notices for everyone;
- audience matching, combined filters, and a class notice reaching that class's teachers;
- the contract shape of a published notice;
- hidden future notices and kept expired ones;
- read marks per reader, a repeat, and an unknown notice;
- audience options;
- publish: every wrong field listed, own classes only, comma lists, attachments with URLs, idempotent replay;
- students on the teacher endpoints.

The full suite passes.

## 8. Open items

- No push notification on publish.
- There is no guardian login, so `guardians` notices reach nobody yet, and the child fields of a feed item are `null`.
- There is no admin login, so notices for the whole school (no classes) cannot be sent from the app yet. The `sender_type = admin` value is ready for it.
- Attachments stay on the local disk until cloud bucket credentials are added, the same as homework files. Until then the web system cannot open them.
- There is no edit or delete endpoint. `status = 0` (removed) is ready for one.
