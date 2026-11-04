# Homework API v1 (Module 4) — Implementation Notes

The mobile app calls these endpoints for homework and assignments. They follow *PATHSHALA Apps — API Contract v1, Module 4*, and run the same rules as the web pages, because writes go through the existing homework models.

## 1. Files

| File | Purpose |
|---|---| 
| `application/controllers/api/v1.php` | Routing, token check, JSON responses, all 11 endpoints |
| `application/models/hw_api_model.php` | Read queries and JSON shapes, idempotency store |
| `tools/make_api_token.php` | **Local testing only.** Makes a login token for Postman |
| `postman/Homework_API_v1.postman_collection.json` | Postman collection with every endpoint |

Writes reuse existing models:

| Action | Model method |
|---|---|
| Create + publish (4.9) | `Hw_assignment_model`: `insertAssignment`, `replaceRubricCriteria`, `replaceFormats`, `insertAttachment`, `publishAssignment`, `uploadAttachmentToCloud` |
| Hand in (4.3) | `Hw_submission_model::submitWork(..., $via = 'app')` |
| Assess (4.10) | `Hw_assessment_model::saveAssessment` |
| Request resubmission (4.11) | `Hw_assessment_model::requestResubmission` |

No existing file was changed. `routes.php` is untouched: CodeIgniter maps the `controllers/api/` folder and the `V1` class to `/api/v1/...` by itself.

## 2. Base URL

| Server | Base URL |
|---|---|
| Local | `http://localhost/pathshala-multi-v3/multi-general-api-v3/api/v1` |
| Live | `<api host>/api/v1` |

No `index.php` in the URL: the root `api/` folder (`api/.htaccess` + `api/index.php`) hands `/api/...` requests to CodeIgniter. Upload that folder to the live server along with the API files. `/index.php/api/v1/...` still works too.

## 3. Authentication

Every request sends the login token:

```
Authorization: Bearer <token>
```

This is the same JWT the existing logins issue:

| Who | Login endpoint | Token holds |
|---|---|---|
| Teacher, Admin, Super Admin | `login/index` | `id` (users.id), `teacher_code`, `user_type`, `database`, `institute_code` |
| Student | `login/student_login` | `current_status_info_id`, `user_type = Student`, `database`, `session_code`, `class_code` |
| Guardian | **not built yet** | must hold `user_type = Guardian` and `student_ids` (the children) |

The server takes the school database from the token. With a token, it never trusts an id in the request body for who the caller is.

### Without a token (testing mode, `V1::NO_TOKEN_MODE = TRUE`)

While the app login is not wired up, a request may leave out the token and name the school and the caller instead. Each value can come as a header, a query-string field or a body field (checked in that order):

| Value | Header | Field | Needed on |
|---|---|---|---|
| School database | `X-Database` | `database` | every request |
| Teacher's `users.id` | `X-User-Id` | `user_id` | `teacher/...` |
| Student | the `{studentId}` in the path | | `students/...` |

Example: `GET /api/v1/teacher/homework?database=pathshala_borderguardsylhet&user_id=1`

The server checks that the database exists, loads the user (or student) from it and builds the same token a login would, so roles and permissions below still apply. The field is `user_id`, not `teacher_id`, because `teacher_id` is already the admin filter in 4.4 / 4.5. A request that sends a token is handled as before and ignores these values.

**This mode has no login check: anyone who knows a database name and an id can act as that user.** Set `NO_TOKEN_MODE` to `FALSE` in `application/controllers/api/v1.php` before the app goes live.

| Role | May use |
|---|---|
| Student | 4.1, 4.2, 4.3, only for their own `studentId` |
| Guardian | 4.1, 4.2 for a child in `student_ids`; 4.3 is refused (403) |
| Teacher | 4.4 to 4.11 for homework they created, or where they are the teacher |
| Admin, Super Admin | 4.4 to 4.11 for every homework |

Tokens expire after 12 hours (set in `Authentication::create_token`).

### 3.1 Temporary: database name as the token

While the app is being built, the school database name can be sent in place of the JWT:

```
Authorization: Bearer pathshala_borderguardsylhet
```

| Path | Caller becomes |
|---|---|
| `/students/{studentId}/...` | That student |
| `/teacher/...` | The user in the `X-User-Id` header (`users.id`); without the header, the school's first Super Admin |

The name must be listed in `school_information.db_name` in the central database; any other name gets 401. The API then builds a normal token internally, so every role check works as usual.

**Turn this off before go-live:** set `$ALLOW_DB_NAME_TOKEN = FALSE` in `application/controllers/api/v1.php`. Anyone who knows a database name can otherwise use the API as a Super Admin or as any student.

## 4. Conventions

`00_Conventions.md` was not available, so these were chosen. Change them in `v1.php` if the conventions document differs.

**Success**

```json
{ "data": ... }
```

**Error**

```json
{ "error": { "code": "validation_failed", "message": "Please correct the highlighted fields.", "fields": { "title": "Required." } } }
```

| HTTP | `code` | When |
|---|---|---|
| 400 | `bad_request` | Body is not valid JSON, Idempotency-Key too long |
| 401 | `unauthorized` | No token, bad or expired token |
| 403 | `forbidden` | Wrong role, another student's data, inactive student, guardian handing in |
| 404 | `not_found` | Unknown path, homework not found, or homework of another teacher (not revealed) |
| 405 | `method_not_allowed` | Wrong HTTP method; the `Allow` header lists the right one |
| 409 | `conflict` | Same Idempotency-Key while the first request is still running |
| 422 | `validation_failed` | Any rule failed; `message` says which |
| 500 | `server_error` | Save or publish failed |
| 503 | `not_set_up` | Homework tables are not created for this school yet |

**Other rules**
- Ids are strings in responses (`"id": "12"`).
- Date-times are UTC ISO 8601 (`2026-09-21T04:00:00Z`). The database stores school time (Asia/Dhaka); the API converts both ways.
- Request bodies: JSON (`Content-Type: application/json`), form fields or multipart. List fields accept a JSON array, repeated `name[]` fields, or `a,b,c`.
- 4.1 and 4.2 ("read, cached") return an `ETag`. Send it back as `If-None-Match`; an unchanged result answers `304 Not Modified` with no body.
- Writes answer `Cache-Control: no-store`.

## 5. Enums

| Enum | Values | Stored as |
|---|---|---|
| `type` | `homework`, `assignment`, `class_work`, `project`, `practical` | `hw_assignment_types.type_name`, as a slug. A type the admin adds later gets a slug the same way |
| `status` | `not_submitted`, `submitted`, `late`, `missing`, `checked`, `resubmit_requested` | see 5.1 |
| `allowed_formats` | `text`, `image`, `pdf`, `document`, `link`, `audio`, `video` | `document` = Word + Excel + PowerPoint |
| `tags` | `excellent`, `very_good`, `good`, `need_improvement`, `incomplete`, `resubmit` | `hw_feedback_tags.tag_name`, as a slug |

### 5.1 Status (decided by the server)

| `hw_assignment_students.status` | Due time | API `status` |
|---|---|---|
| `pending` | not passed | `not_submitted` |
| `pending` | passed | `missing` |
| `missing` | any | `missing` |
| `submitted` / `under_review`, not late | any | `submitted` |
| `submitted` / `under_review`, `is_late = 1` | any | `late` |
| `resubmission_requested` | any | `resubmit_requested` |
| `checked` | any | `checked` |

Late is set at hand-in time from the server clock and never changes. A resubmission request may give one student a later due time (`new_due_at`); 4.1 shows that date in `due_at`.

## 6. Endpoints

### 6.1 Student and guardian

#### 4.1 GET `/students/{studentId}/homework`

Every published or closed homework of the student, newest due date first. Each item is complete, so the detail screen needs no other call.

```json
{
  "data": [
    {
      "id": "1",
      "title": "বীজগণিত — অনুশীলনী ৪.৩",
      "description": "১–১২ নম্বর সমাধান করো",
      "chapter": "Chapter 4",
      "type": "homework",
      "subject": "গণিত",
      "teacher_name": "জনাব হাসান",
      "status": "checked",
      "due_at": "2026-09-21T04:00:00Z",
      "total_marks": 10,
      "passing_marks": 4,
      "allowed_formats": ["text", "image", "pdf"],
      "references": [
        { "name": "অনুশীলনী-৪.৩.pdf", "url": "https://storage.googleapis.com/.../43.pdf", "size_bytes": 120000 }
      ],
      "submissions": [
        {
          "version": 1,
          "submitted_at": "2026-09-20T05:00:00Z",
          "is_late": false,
          "text": "উত্তর: ১) ৪ ২) ৭",
          "link": "https://example.com/my-work",
          "links": ["https://example.com/my-work"],
          "files": [ { "name": "page-1.jpg", "url": "https://...", "size_bytes": 512000 } ]
        }
      ],
      "assessment": {
        "obtained_marks": 8,
        "total_marks": 10,
        "feedback": "ভালো হয়েছে।",
        "tags": ["good"],
        "criteria": [ { "name": "সঠিকতা", "obtained": 5, "max": 6 } ],
        "checked_at": "2026-09-21T05:00:00Z"
      },
      "resubmission_reason": null
    }
  ]
}
```

| Field | Note |
|---|---|
| `references` | Teacher files and reference links. A link has `name = url`, `size_bytes = null` |
| `submissions` | Every version, oldest first. Old versions are never overwritten |
| `link` / `links` | `link` is the first link (contract field); `links` lists all |
| `assessment` | Of the latest version; `null` until checked. `criteria` is empty in simple mode |
| `resubmission_reason` | Set only when `status = resubmit_requested` |
| `chapter` | Extra field, not in the contract |

#### 4.2 GET `/students/{studentId}/homework/summary`

```json
{
  "data": {
    "total": 12, "completed": 8, "pending": 2, "missing": 1, "late": 1,
    "average_score_percent": 82.5,
    "on_time_percent": 87.5,
    "completion_percent": 75.0,
    "late_percent": 8.3,
    "missing_percent": 8.3,
    "by_subject": [
      { "subject": "গণিত", "total": 4, "submitted": 3, "late": 1, "missing": 0, "average_percent": 85.0 }
    ]
  }
}
```

| Field | Rule |
|---|---|
| `completed` | status `submitted` or `checked` |
| `pending` | status `not_submitted` or `resubmit_requested` |
| `missing`, `late` | that status |
| | `total = completed + pending + missing + late` |
| `average_score_percent` | average of checked work only; `null` when none |
| `on_time_percent` | handed in on time ÷ all handed in |
| `completion_percent` | handed in (any version) ÷ total |
| `late_percent`, `missing_percent` | that count ÷ total |
| `by_subject[].submitted` | handed in (any version), late included |
| `by_subject[].average_percent` | checked work only |

Percentages are 0–100 with one decimal, or `null` when there is nothing to divide by.

#### 4.3 POST `/students/{studentId}/homework/{homeworkId}/submissions`

`multipart/form-data`. Student only; never queue it offline, because the server time on arrival decides late.

| Field | Required | Note |
|---|---|---|
| `text` | at least one of the three | Needs `text` in allowed_formats; at most 20,000 characters |
| `link` | | One http(s) URL; needs `link` in allowed_formats |
| `files` / `files[]` | | Repeat for more; at most 10. The extension must match an allowed format |

| File extension | Format |
|---|---|
| jpg jpeg png gif webp | image |
| pdf | pdf |
| doc docx xls xlsx csv ppt pptx | document |
| mp3 m4a wav ogg aac | audio |
| mp4 mov webm 3gp mkv | video |

Size limit: `hw_assignment_formats.max_file_size_mb`, or 20 MB when not set. The PHP `upload_max_filesize` and `post_max_size` on the server must allow the whole request.

**Response:** `201` with the updated homework object (same shape as one 4.1 item).

| Case | Answer |
|---|---|
| Guardian token | 403 |
| Student not active | 403 |
| Homework not assigned to this student, draft or deleted | 404 |
| Already handed in, no resubmission request (and free resubmission is off) | 422 |
| Already checked, no request | 422 |
| `max_versions` reached | 422 |
| Homework closed, or not open yet (before assigned date + start time) | 422 |
| Past due and the school does not accept late work (`hw_settings.allow_late_submission = 0`) | 422 |
| Nothing sent, wrong format, too large | 422 |

Files are uploaded to cloud storage first; if the database save fails, they are deleted again.

### 6.2 Teacher

Teacher, Admin and Super Admin tokens only.

**Who sees what**

| Caller | Homework they see (4.4, 4.5, and 4.6 to 4.11) |
|---|---|
| Teacher | Only their own: homework they created, or homework that names them as teacher (`teacher_id` = their `teacher_code`). Another teacher's homework id returns 404 |
| Admin, Super Admin | All homework in the school, and they can filter by teacher |

**List filters (4.4 and 4.5)**

All are optional query-string parameters and can be combined. For example: `/teacher/homework?class_id=12&subject_id=143&status=published&teacher_id=7`.

| Parameter | Value | Who |
|---|---|---|
| `session_id` | `session_code` (from filter options) | All |
| `class_id`, `section_id`, `subject_id` | From 4.8 | All |
| `type` | Type slug (`homework`, `class_work` ...) or its numeric id | All |
| `status` | `draft`, `published`, `closed` (4.4 counts only published and closed, whatever this says) | All |
| `due_from`, `due_to` | `YYYY-MM-DD`, inclusive, school time | All |
| `search` | Text in the title or chapter, up to 100 characters | All |
| `teacher_id` | `teacher_code` (from filter options). Matches homework that names the teacher or was created by that teacher's login | **Admin, Super Admin only**. A Teacher token sending it gets 403 |

An invalid value returns 422 `validation_failed`, with each bad parameter listed in `fields`. A Teacher's filters only narrow down their own homework.

#### GET `/teacher/homework/filter-options`

Values for the filter dropdowns. Classes, sections and subjects come from 4.8.

```json
{
  "data": {
    "sessions": [ { "id": "15", "name": "2026", "running": true } ],
    "types": [ { "id": "homework", "name": "Homework" }, { "id": "class_work", "name": "Class Work" } ],
    "statuses": ["draft", "published", "closed"],
    "teachers": [ { "id": "7", "name": "MD. IMRAN NAZIR", "designation": "Assistant Teacher" } ]
  }
}
```

`teachers` is filled only for Admin and Super Admin. For a Teacher it is an empty list.

#### 4.4 GET `/teacher/homework/dashboard`

```json
{
  "data": {
    "totals": { "assigned": 6, "submitted": 96, "pending": 20, "missing": 8, "under_review": 14, "checked": 82 },
    "recent": [ /* up to 6 assignment objects, see 4.5 */ ]
  }
}
```

- `totals.assigned` = number of published or closed homework (matches the contract example). The other totals are student counts added over that homework.
- `recent` = the 6 newest published or closed homework.
- Takes the list filters above, and the totals cover only the filtered homework.

#### 4.5 GET `/teacher/homework`

All the homework the caller may see (see "Who sees what"), newest first, drafts included (drafts come from the web). Takes the list filters above.

```json
{
  "data": [
    {
      "id": "3",
      "title": "বীজগণিত — অনুশীলনী ৪.৩",
      "description": "১–১২ নম্বর সমাধান করো",
      "chapter": "Chapter 4",
      "type": "homework",
      "class": "অষ্টম",
      "section": "ক",
      "subject": "গণিত",
      "teacher": { "id": "7", "name": "MD. IMRAN NAZIR" },
      "created_by": { "id": "1709", "name": "MD. IMRAN NAZIR" },
      "status": "published",
      "due_at": "2026-09-21T04:00:00Z",
      "total_marks": 10,
      "passing_marks": 4,
      "rubric": [ { "name": "সঠিকতা", "max": 6 }, { "name": "Presentation", "max": 4 } ],
      "allowed_formats": ["text", "image"],
      "counts": { "assigned": 38, "submitted": 30, "pending": 5, "missing": 3, "under_review": 12, "checked": 18 }
    }
  ]
}
```

| Field | Note |
|---|---|
| `section` | `null` = all sections of the class |
| `rubric` | Empty = simple mode (one mark) |
| `status`, `chapter`, `allowed_formats` | Extra fields, not in the contract |
| `teacher` | The teacher the homework names (`teacher_code`), or `null` when none is set |
| `created_by` | The login (`users.id`) that created it. With `teacher`, this shows admins whose homework it is |
| `counts.submitted` | Students who handed in at least once |
| `counts.pending` | Not handed in and not yet due, plus students asked to resubmit |
| `counts.missing` | Not handed in and past due |
| `counts.under_review` | Handed in, not yet checked |
| `counts.checked` | Assessed |

#### 4.6 GET `/teacher/homework/{homeworkId}/submissions`

One row per assigned student, by roll, including students with no submission.

```json
{
  "data": [
    { "student_id": "4723", "student_name": "রহিম আহমেদ", "roll": "12", "status": "submitted", "is_late": false, "submitted_at": "2026-09-20T03:00:00Z", "versions": 1 },
    { "student_id": "4724", "student_name": "মারিয়া ইসলাম", "roll": "7", "status": "not_submitted", "is_late": false, "submitted_at": null, "versions": 0 }
  ]
}
```

The contract example shows `pending`; this returns `not_submitted` to match the status enum.

#### 4.7 GET `/teacher/homework/{homeworkId}/submissions/{studentId}`

```json
{
  "data": {
    "student_id": "4723",
    "student_name": "আরিফ হোসেন",
    "roll": "15",
    "status": "resubmit_requested",
    "resubmission_reason": "প্রশ্ন ৪ ও ৫ ঠিক করতে হবে।",
    "versions": [
      { "version": 1, "submitted_at": "2026-09-19T05:00:00Z", "is_late": false, "text": "উত্তর: ...", "link": null, "links": [], "files": [ { "name": "page-1.jpg", "url": "https://...", "size_bytes": 410000 } ] }
    ],
    "assessment": null
  }
}
```

`versions` is an array here (oldest first); in 4.6 it is a count. `assessment` belongs to the latest version and is `null` until checked.

#### 4.8 GET `/teacher/teaching-options`

```json
{
  "data": [
    {
      "id": "8",
      "name": "অষ্টম",
      "sections": [ { "id": "1", "name": "ক" }, { "id": "2", "name": "খ" } ],
      "subjects": [ { "id": "143", "name": "গণিত" } ]
    }
  ]
}
```

| Caller | Source |
|---|---|
| Teacher | Their `subject_teacher_info` rows in the running session; if none, their latest session. `section_code = 0` there means every section |
| Admin, Super Admin | Every active class, with all active sections and the class's active subjects |

A teacher with no `subject_teacher_info` rows gets an empty list and cannot create homework from the app.

#### 4.9 POST `/teacher/homework`

`multipart/form-data` (JSON also works when there are no files). Creates **and publishes**: every active student of the class (and section, shift, group when given) in the running session gets the homework at once, and an `assigned` notification event is written.

Header `Idempotency-Key: <unique per tap>` is recommended:
- The same key again → the first response is returned with `Idempotent-Replayed: true`, and nothing new is created.
- The same key while the first request is still running → 409.
- A request that fails frees its key, so the app can retry with the same one.

| Field | Required | Note |
|---|---|---|
| `class_id` | yes | From 4.8 |
| `section_id` | no | From 4.8. Left out = all sections |
| `subject_id` | yes | From 4.8, for that class |
| `type` | yes | Enum slug (or the type's numeric id) |
| `title` | yes | Up to 255 characters |
| `description` | yes | Instructions |
| `chapter` | no | Up to 255 characters |
| `due_at` | yes* | UTC date-time, e.g. `2026-09-21T04:00:00Z`. Must be in the future |
| `due_date` + `due_minutes` | yes* | Instead of `due_at`: `YYYY-MM-DD` + minutes after midnight in school time (600 = 10:00) |
| `total_marks` | yes | > 0 |
| `passing_marks` | no | 0 to total_marks |
| `allowed_formats` | yes | Repeat `allowed_formats[]`, at least one |
| `rubric` | no | `rubric[0][name]`, `rubric[0][max]`, ... The max values must add up to `total_marks`. Empty = simple mode |
| `files` / `files[]` | no | Attachments: pdf jpg jpeg png doc docx xls xlsx ppt pptx, up to 20 MB each, at most 10 |
| `group_id`, `shift_id` | no | CRS 3.1 proposal. Left out = all |
| `assigned_date` | no | CRS 3.1 proposal. `YYYY-MM-DD`, default today |
| `start_time` | no | CRS 3.1 proposal. `HH:MM`; students cannot hand in before assigned date + start time |
| `priority` | no | CRS 3.1 proposal. `low`, `medium` (default), `high` |
| `teacher_id` | no | **Admin, Super Admin:** `teacher_code` from filter-options → `teachers`; the homework is that teacher's, so it shows in their list, dashboard and submissions. Left out = no teacher. **Teacher:** always their own; another teacher's code gets 403 |

\* one of the two.

Other rules:
- The session is the running session.
- `teacher_id` is the caller's `teacher_code`.
- Free resubmission follows `hw_settings.allow_free_resubmission`.
- When `hw_settings.allow_mode_override = 0`, the school's default mode wins: a rubric is required, or not allowed.
- No active students for the target → 422, nothing saved.

**Response:** `201`

```json
{ "data": { "id": "17" } }
```

On 422, `fields` names every wrong field at once, for example:

```json
{ "error": { "code": "validation_failed", "message": "Please correct the highlighted fields.",
  "fields": { "subject_id": "Choose one of your subjects for this class.", "rubric": "The max values add up to 6 but total_marks is 10." } } }
```

#### 4.10 POST `/teacher/homework/{homeworkId}/submissions/{studentId}/assessment`

JSON or form body. Assesses the student's **latest** version; sending again updates the same assessment.

Simple mode:

```json
{ "obtained_marks": 8, "criteria": [], "tags": ["good"], "feedback": "ভালো হয়েছে।" }
```

Rubric mode:

```json
{
  "criteria": [ { "name": "সঠিকতা", "obtained": 4 }, { "name": "উপস্থাপনা", "obtained": 2 } ],
  "tags": ["good"],
  "feedback": "ভালো হয়েছে।"
}
```

| Field | Rule |
|---|---|
| `obtained_marks` | Simple mode: 0 to total_marks. Ignored in rubric mode (the server sums the criteria) |
| `criteria[].name` | Must match a rubric name of this homework (case and spaces ignored). Every criterion needs a mark |
| `criteria[].obtained` | 0 to that criterion's max |
| `tags` | Enum slugs, several allowed |
| `feedback` | Up to 2,000 characters |

**Response:** `200` with the 4.7 object, `status = checked`.

| Case | Answer |
|---|---|
| Nothing handed in yet | 422 |
| A resubmission request is open (waiting for the student) | 422 |
| Unknown criterion name or tag, mark out of range | 422 |

#### 4.11 POST `/teacher/homework/{homeworkId}/submissions/{studentId}/resubmission`

```json
{ "reason": "প্রশ্ন ৪ ও ৫ ঠিক করতে হবে।", "new_due_at": "2026-09-25T04:00:00Z" }
```

| Field | Required | Note |
|---|---|---|
| `reason` | yes | Up to 1,000 characters; empty → 422 |
| `new_due_at` | no | Extra field. A later due time for this student only; must be in the future |

**Response:** `200` with the 4.7 object, `status = resubmit_requested`. The latest version stays in history as `returned`; the student can now send the next version.

| Case | Answer |
|---|---|
| Nothing handed in yet | 422 |
| A request is already open | 422 |
| Homework closed, or `max_versions` used up | 422 |

#### 4.12 GET `/teacher/homework/{homeworkId}`

One homework, for the edit screen. It is the 4.5 item plus:

| Field | Note |
|---|---|
| `form` | Raw ids to prefill the form: `class_id`, `section_id`, `shift_id`, `group_id`, `subject_id`, `teacher_id` (teacher_code), `assigned_date`, `start_time` (HH:MM), `priority` |
| `attachments[]` | Teacher files: `id`, `name`, `url`, `size_bytes`. The `id` is what 4.13 `remove_attachment_ids` takes |
| `references[]` | Reference links added on the web: `id`, `url` |
| `locks` | What 4.13 / 4.14 will refuse: `editable` (false = closed), `target` (class, section, shift, group fixed once published), `marks` (total and rubric fixed once something is assessed), `delete` (someone has submitted) |

#### 4.13 PATCH `/teacher/homework/{homeworkId}` (or POST, to add files)

Edits a homework. **Only the fields sent change**; everything else stays. Send JSON with `PATCH`. To upload files, send `multipart/form-data` with `POST` to the same URL (PHP does not read files from a PATCH body).

Fields are the same as 4.9: `class_id`, `section_id`, `subject_id`, `type`, `title`, `description`, `chapter`, `due_at` (or `due_date` + `due_minutes`), `assigned_date`, `start_time`, `total_marks`, `passing_marks`, `rubric`, `allowed_formats`, `priority`, `group_id`, `shift_id`, `teacher_id` (Admin only). An empty value clears an optional field (`section_id` = all sections, `chapter`, `passing_marks`, `start_time`, `teacher_id`; `rubric: []` = simple marks). Plus:

| Field | Note |
|---|---|
| `files` (POST multipart) | New attachments, added to the ones already there. At most 10 per homework |
| `remove_attachment_ids` | Attachment ids from 4.12 to delete (also removed from cloud storage) |
| `publish` | `true` publishes a draft made on the web, after saving |

Rules (the same as the web form):

| Case | Answer |
|---|---|
| Homework closed | 422 |
| Published, and `class_id`, `section_id`, `shift_id` or `group_id` changed | 422 (subject, dates, marks and text can still change) |
| Something already assessed, and `total_marks`, rubric or mode changed | 422 |
| Rubric homework: `total_marks` sent without a matching `rubric` | 422 (send both) |
| New `due_at` in the past | 422 |
| Teacher sends another teacher's `teacher_id` | 403 |
| No field to change | 422 |

Every field is checked first; one bad field means **nothing** changes (422, with each field in `fields`). Response: 200 with the 4.12 object.

```json
PATCH /api/v1/teacher/homework/3
{ "title": "বীজগণিত — অনুশীলনী ৪.৪", "due_at": "2027-01-15T04:00:00Z", "allowed_formats": ["text", "pdf"] }
```

#### 4.14 DELETE `/teacher/homework/{homeworkId}`

Deletes the homework (a soft delete, as on the web). Refused with 409 `conflict` once any student has submitted, so students' work is never lost. Response: `{ "data": { "id": "3", "deleted": true } }`.

## 7. Testing in Postman

1. Import `postman/Homework_API_v1.postman_collection.json`.
2. Make tokens on your machine (the login endpoints need the client site's Referer and the school's encrypted secret, so they cannot be called from Postman directly):

   ```
   php tools/make_api_token.php staff   <users.id>               <school_db>
   php tools/make_api_token.php student <current_status_info_id> <school_db>
   ```

   Example (local data): `staff 1` (Super Admin) and `student 4723`, both with `pathshala_borderguardsylhet`.
3. Set the collection variables (request URLs already point at local `http://localhost/pathshala-multi-v3/multi-general-api-v3/api/v1`):

   | Variable | Example |
   |---|---|
   | `teacher_token` | output of the staff command |
   | `student_token` | output of the student command |
   | `student_id` | `4723` (same student as the token) |
   | `class_id`, `section_id`, `subject_id` | `12`, `4`, `143` (local data with active students) |

4. Run **4.9 Create homework**: it stores the new id in `homework_id`. Then run the student's **4.3 Hand in**, then the teacher's **4.6 / 4.7 / 4.10 / 4.11**.

Notes:
- `tools/make_api_token.php` runs only from the command line. **Do not copy `tools/` to the live server.**
- With curl on Windows, Bangla typed as a command-line argument arrives as `???`. Postman and the app send it correctly. With curl, send it from a UTF-8 file (`-F "title=<title.txt"`, `--data-binary @body.json`).

## 8. Test results (local, 2026-10-09)

| Check | Result |
|---|---|
| No token / bad token | 401 |
| Student token on teacher endpoints, or another student's id | 403 |
| Another teacher's homework | 404 |
| Wrong method | 405 with `Allow` |
| Create: missing / wrong fields | 422 with every field listed |
| Create with rubric + Idempotency-Key, then the same key again | 201, replay returns the same id, one homework in the DB |
| Student list after create | homework shown as `not_submitted` |
| Hand in text + link | 201, version 1, `submitted` |
| Hand in again without a request | 422 |
| Hand in nothing / a pdf when pdf is not allowed | 422 |
| Assess before hand-in | 422 |
| Rubric mark above max / unknown tag | 422 |
| Rubric assessment | 200, `checked`, criteria and tags returned |
| Simple-mode assessment | 200, `checked` |
| Resubmission with empty reason | 422 |
| Resubmission request → student sees `resubmit_requested` and the reason → version 2 | works; version 1 kept |
| Summary, then the same request with `If-None-Match` | 200, then 304 |
| Bangla text through the API | stored and returned as UTF-8 |
| **File uploads (4.3, 4.9)** | **not tested**: they write to the real cloud bucket |

Test data left in the local database: homework 1, 2 and 3 (class 12, section 4), with submissions and assessments from student 4723. Homework 1 has a `???` title from the curl test.

## 9. Open items

1. **Conventions.** Check the error format, id format and status names against `00_Conventions.md`.
2. **Guardian login.** No guardian token exists yet. The API accepts one with `user_type = Guardian` and `student_ids`.
3. **Teacher mapping.** A teacher needs `subject_teacher_info` rows to create homework from the app. Confirm that this is how schools set teachers up.
4. **File uploads.** Test 4.3 and 4.9 with a small file on a test school, and check the server's `upload_max_filesize` and `post_max_size`.
5. **Not in the contract yet** (CRS proposals): star rating and weekly trend in 4.2, reports, and listing notifications through `/api/v1/notifications`. The `hw_notification_events` rows these would read are already written on assign, submit, late, assess and resubmission request.
