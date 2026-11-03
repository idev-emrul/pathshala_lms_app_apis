# Student Profile API v1 (Module 1)

These are the student profile endpoints for the mobile app. They follow *PATHSHALA Apps — API Contract v1, Module 1* (`PATHSHALA_API_v1.pdf`), and they are built the same way as the homework API. They use this project's envelope, `{ status, message, data }`.

The profile is only read. A correction request (1.2) is stored for the school to review, and it never changes the profile itself.

| File | What it holds |
|---|---|
| `app/Http/Controllers/StudentProfile/StudentProfileController.php` | 1.1 profile, 1.2 correction request |
| `app/Http/Controllers/StudentProfile/TeacherClassListController.php` | 1.3 class list |
| `app/Services/StudentProfile/ProfileReader.php` | Queries, JSON shapes, masking, photo URL, teacher access check |
| `app/Services/StudentProfile/ChangeRequestService.php` | Stores 1.2 in `app_stu_profile_change_request` through `tenant_profile` |
| `app/Services/StudentProfile/ProfileCaller.php` | Who is calling, built by the middleware |
| `app/Http/Middleware/AuthorizeProfile.php` | `profile` (both roles), `profile:student`, `profile:teacher` |
| `tests/Feature/ProfileApiTest.php` | Feature tests |
| `postman/Pathshala_Profile_API.postman_collection.json` | Login, then all three endpoints |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://127.0.0.1:8000/api/v1` |
| Live | `<api host>/api/v1` |

Every request needs `Authorization: Bearer <token>`, from Set PIN or PIN login. The token decides the school and the caller.

| Caller | May use |
|---|---|
| Student (verified) | 1.1 and 1.2, only with their own `current_status_info_id`s. 1.2 needs an active placement |
| Teacher (verified, active login) | 1.1 for a student of a class they teach as class teacher or subject teacher, in a running session. 1.3 for their own class |
| Guest, or the other role on a role-only route | 403 |

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 1.1 | GET | `/students/{studentId}/profile` | The full profile |
| 1.2 | POST | `/students/{studentId}/profile/change-requests` | Ask the school to correct a field |
| 1.3 | GET | `/teacher/my-class/students` | The class teacher's students |

`{studentId}` is `current_status_info_id`. The app gets it from `/auth/me` as `placement.homework_student_id`, and from the `student_id` field of 1.3.

### 1.1 response (`data`)

```json
{
  "student_id": "1234",
  "name_en": "Rahim Uddin", "name_bn": "রহিম উদ্দিন",
  "student_no": "107837", "roll_no": "5", "admission_no": null,
  "status": "active",
  "session": "2026", "class": "Six", "section": "A", "group": null, "shift": "Morning", "branch": null,
  "class_teacher": "Rahima Khatun",
  "admission_date": "2024-01-02", "admission_class": "Four",
  "student_type": "General", "student_category": "Civil",
  "date_of_birth": "2014-05-01", "gender": "male", "religion": "Islam", "blood_group": "B+", "nationality": "Bangladeshi",
  "nid": { "masked": null, "full": null },
  "birth_reg": { "masked": "**** **** **** 5521", "full": null },
  "birth_reg_date": null,
  "present_address": "...", "permanent_address": "...",
  "photo_url": "https://.../student/540/107837.jpg",
  "guardians": [
    { "relation": "Father", "name": "...", "mobile": null, "profession": null, "email": null, "nid": { "masked": "** **** 1234", "full": null } },
    { "relation": "Guardian", "name": "...", "mobile": "01711000000", "profession": "Business", "email": null, "nid": { "masked": null, "full": null } }
  ],
  "history": [
    { "session": "2025", "class": "Five", "section": "A", "roll": "3", "gpa": 5, "status": "Promoted" },
    { "session": "2026", "class": "Six", "section": "A", "roll": "5", "gpa": null, "status": "Current" }
  ],
  "documents": []
}
```

### 1.2 request

JSON body: `{ "field": "father_name", "requested_value": "...", "note": "..." }`.

| Field | Rule |
|---|---|
| `field` | Required, at most 100 characters. It is free text: the server does not check it against a field list |
| `requested_value` | Required, at most 1000 characters |
| `note` | Optional, at most 1000 characters |

The `Idempotency-Key` header is optional. The answers are:

- a new request: 201 "Correction request sent";
- the same key again: 200 "Correction request already sent", with the header `Idempotent-Replayed: true`.

### 1.3 response (`data`)

```json
[ { "student_id": "1234", "name": "Rahim Uddin", "roll": "5", "status": "active" } ]
```

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| `student_id` | `current_status_info_id` of the **current** placement (see §4) |
| `student_no` | `student_info.student_registration_code`, padded to 6 digits |
| `roll_no`, session / class / section / group / shift | The current placement and its lookups |
| `status` | `active_status`: 1 `active`, 2 `transfer`, 3 or 4 `inactive` |
| `class_teacher` | `class_teacher_info` for the class and section (0 = all). The master teacher comes first |
| `admission_class` | The class of the oldest placement |
| `student_type` | `remission_info.remission_name` |
| `student_category`, `religion` | `category_info`, `religion_infos` |
| `gender` | 1 `male`, 2 `female`, 3 `other` |
| `blood_group` | `student_bg` code 1–8: A+, A-, B+, B-, O+, O-, AB+, AB- |
| `nid` | Students have no NID column, so it is always `{ masked: null, full: null }` |
| `birth_reg` | `birth_cer_number`, masked |
| `photo_url` | Built the way the web profile builds it: `institute_storage_path/<EIIN>/student/<ceil(reg/200)>/<reg><ext>`. An `img_ext` that starts with `-` came from the website admission form and points to `http://<institute_website>/images/application/...` |
| `guardians` | Father, mother and guardian from `student_info`. The school keeps one phone number (`contact_number`), which is shown on the guardian block. A block with no name and no mobile is left out |
| `history` | One row per placement (not deleted), oldest first. `gpa` is the year's final `exam_overall_result.average_gpa`, capped at 5 (5.33 is the web system's "golden A+" marker). `status` is `Current`, `Transfer`, `Inactive` or `Promoted` |
| `admission_no`, `branch`, `birth_reg_date`, `documents` | The school has no data for them: `null` / `[]` |

Text such as `N/A`, `0` or an empty string counts as no value and is sent as `null`. A zero date (`0000-00-00`) is also `null`.

## 4. Rules

- **Current placement:** the latest active row, else the latest transfer or inactive row. A student asking with an older id of their own still gets the current profile. A deleted row (`active_status = 5`) is 404.
- **Masking:** NID and birth registration are `{ masked, full }`. `full` is always `null`. `masked` shows only the last 4 digits, in groups of 4 from the right.
- **Teacher view:** a teacher does not get the guardian's mobile, email or NID. They come back as `null`, and the NID as `{ masked: null, full: null }`.
- **Teacher access (1.1):** the teacher must have a row in `class_teacher_info` or `subject_teacher_info` for the student's session and class, and the session must be running. A section, group or shift of 0 in that row means all. Otherwise the answer is 403.
- **1.3:** students of every class / section the teacher is class teacher of, in running sessions, with status active, transfer or inactive. They are sorted by class, section and roll. A teacher who is not a class teacher gets `[]`.
- **1.2:**
  - only a student whose current placement is active may send one (otherwise 403);
  - the request body is stored as JSON in `request_data`, with a snapshot of the placement, `status = 0` (pending) and `created_at` in Dhaka time;
  - the school reviews it on the web side (`status` 1 approved, 2 rejected). That screen is not part of this project;
  - it is logged as `activity:request_profile_change`.

## 5. Table (run on every school database)

The write connection is `tenant_profile`, and it writes only to this table.

```sql
CREATE TABLE IF NOT EXISTS `app_stu_profile_change_request` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_registration_code` int(12) NOT NULL,
  `current_status_info_id` int(11) NOT NULL,
  `session_code` int(10) DEFAULT NULL,
  `session_name` varchar(20) DEFAULT NULL,
  `class_code` int(11) DEFAULT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `section_code` int(11) DEFAULT NULL,
  `group_code` int(11) DEFAULT NULL,
  `shift_code` int(11) DEFAULT NULL,
  `roll_number` varchar(20) DEFAULT NULL,
  `student_status` tinyint(1) DEFAULT NULL COMMENT 'active_status at request time',
  `request_data` longtext NOT NULL COMMENT 'request body from the app, as JSON',
  `idempotency_key` varchar(100) DEFAULT NULL,
  `app_user_id` int(11) DEFAULT NULL COMMENT 'app_user.id of the sender (master DB)',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 pending, 1 approved, 2 rejected',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text,
  `created_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency_key` (`idempotency_key`),
  KEY `idx_student` (`student_registration_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

## 6. Errors

Errors use the envelope `{ "status": false, "message": "...", "errors"?: { field: [..] } }`.

| HTTP | Cases |
|---|---|
| 401 | No token, a bad token, or a token that was logged out |
| 403 | Another student's id; a teacher who does not teach the student; a student not active (1.2); the wrong role; a guest; a banned teacher |
| 404 | The student row is not found or is deleted |
| 422 | A 1.2 field is missing or too long (`errors.field`, `errors.requested_value`, `errors.note`) |
| 429 | More than 120 requests a minute from one IP (`throttle:profile`) |

## 7. Tested on local (2026-11-03)

`ProfileApiTest` runs on `pathshala_app_test_school`. It covers:

- the full profile;
- an older row showing the current placement, and deleted or unknown students;
- another student's profile;
- the teacher view without guardian contact, and a teacher who does not teach the student;
- the class list in roll order, an empty list for a teacher who is not a class teacher, and a student refused;
- correction requests: stored with a placement snapshot, one request per key, required fields, active student only;
- guests.

The full suite passes.

## 8. Open items

- There is no web screen yet for the school to review correction requests. They stay `status = 0` until one is built.
- `field` is free text. If the app team gives a fixed field list, it can be checked on the server.
- `documents` is `[]`: the school stores no student documents.
