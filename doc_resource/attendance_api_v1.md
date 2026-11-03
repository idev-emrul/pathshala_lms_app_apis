# Attendance API v1 (Module 10)

These are the attendance endpoints for the mobile app. They follow *PATHSHALA Apps — API Contract v1, Module 10, Students' Live Attendance Status* (`PATHSHALA_API_v1.pdf`), and they are built the same way as the homework API. They use this project's envelope, `{ status, message, data }`.

- Students read their attendance the way the web profile's attendance search shows it.
- The class teacher marks the register the way the web register does. Each mark goes into the day columns of `attendance_info`.
- 10.4, the attendance PDF, is built by the app team and is not part of this API.

| File | What it holds |
|---|---|
| `app/Http/Controllers/Attendance/StudentAttendanceController.php` | 10.1 report, 10.2 month, 10.3 summary |
| `app/Http/Controllers/Attendance/TeacherAttendanceController.php` | 10.5 register, 10.6 submit |
| `app/Services/Attendance/AttendanceReader.php` | Day codes, holidays, counts and percentages, the register read |
| `app/Services/Attendance/RegisterService.php` | 10.6 writes through `tenant_attendance`, the leave rule, the class teacher's sections |
| `app/Services/Attendance/AttendanceCaller.php` | Who is calling, built by the middleware |
| `app/Http/Middleware/AuthorizeAttendance.php` | `attendance:student`, `attendance:teacher` |
| `tests/Feature/AttendanceApiTest.php` | Feature tests |
| `postman/Pathshala_Attendance_API.postman_collection.json` | Login, then all five endpoints |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://127.0.0.1:8000/api/v1` |
| Live | `<api host>/api/v1` |

Every request needs `Authorization: Bearer <token>`. The token decides the school and the caller.

| Caller | May use |
|---|---|
| Student (verified) | 10.1–10.3, only with their own `current_status_info_id`s |
| Teacher (verified, active login) | 10.5 and 10.6, for the classes they are class teacher of in a running session |
| Guest, or the other role | 403 |

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 10.1 | GET | `/students/{studentId}/attendance/report?from=YYYY-MM-DD&to=YYYY-MM-DD` | Attendance for a duration |
| 10.2 | GET | `/students/{studentId}/attendance?month=YYYY-MM` | Every day of one month |
| 10.3 | GET | `/students/{studentId}/attendance/summary` | The session, with a monthly chart |
| 10.4 | | | The PDF report. Built by the app team |
| 10.5 | GET | `/teacher/attendance/register?date=YYYY-MM-DD&section_id=` | The class register for a date |
| 10.6 | POST | `/teacher/attendance/register` | Submit the marked register |

- `{studentId}` is `current_status_info_id`, from `/auth/me` as `placement.homework_student_id`.
- 10.1:
  - `from` and `to` are optional;
  - without `from`, the report starts on 1 January of the session year;
  - without `to`, it ends today;
  - `to` before `from` is 422.
- 10.2: `month` is required.
- 10.5: `date` is required. `section_id` is only needed when the teacher is class teacher of more than one section; without it, the first one is used.

### The day object (10.1 `recent`, 10.2 `days`)

```json
{ "date": "2026-10-15", "status": "present", "check_in": null, "check_out": null,
  "marked_by": "Rahima Khatun", "marked_at": "2026-10-15T03:10:00Z", "note": null }
```

`status` is one of `present`, `absent`, `leave`, `holiday`, `fugitive` or `pending`. There is no `late`, because the school does not record it.

### 10.1 response (`data`)

```json
{
  "academic_year": "2026",
  "term_id": null,
  "years": ["2026", "2025"],
  "terms": [],
  "from": "2026-01-01",
  "to": "2026-11-03",
  "student_name": "Rahim Uddin",
  "class_label": "Six - A",
  "generated_at": "2026-11-03T06:00:00Z",
  "today": { "date": "2026-11-03", "status": "present", "check_in": null, "updated_at": "2026-11-03T03:10:00Z" },
  "working_days": 220,
  "counts": { "present": 190, "absent": 10, "leave": 5, "fugitive": 1, "holiday": 87, "pending": 20 },
  "percentages": { "present": 84.1, "absent": 4.4, "leave": 2.2, "fugitive": 0.4 },
  "rate": 84.1,
  "recent": [ { "date": "2026-11-03", "status": "present", "...": "..." } ]
}
```

`recent` holds the last 10 marked days in the duration, newest first.

### 10.2 response (`data`)

```json
{ "year": 2026, "month": 10, "days": [ { "date": "2026-10-01", "status": "present", "...": "..." } ] }
```

The list covers every day of the month up to today. A future month has `days: []`.

### 10.3 response (`data`)

```json
{
  "percentage": 84.1,
  "counts": { "present": 190, "absent": 10, "leave": 5, "fugitive": 1, "holiday": 87, "pending": 20 },
  "monthly": [ { "month": 1, "percentage": 90.5 }, { "month": 2, "percentage": 88 } ]
}
```

The session runs from 1 January of the session year to today. A session named like `2026-2027` ends on 31 December of its second year. `monthly` only includes months that have at least one marked day.

### 10.5 response (`data`)

```json
{
  "section_id": "12-5-1-0-0",
  "date": "2026-10-15",
  "class_label": "Six - A",
  "marking_enabled": true,
  "submitted_at": "2026-10-15T03:10:00Z",
  "students": [ { "student_id": "1234", "name": "Rahim Uddin", "roll": "5", "status": "present", "leave_reason": null } ]
}
```

- A student who is not marked yet has `status: null`.
- `marking_enabled` is false for a future date.
- `submitted_at` is the latest app submission for this register and date, or `null`.

### 10.6 request

```json
{
  "section_id": "12-5-1-0-0",
  "date": "2026-10-15",
  "marks": [ { "student_id": "1234", "status": "present" }, { "student_id": "1235", "status": "absent" } ]
}
```

| Field | Rule |
|---|---|
| `section_id` | Required. One of the teacher's register ids (from 10.5) |
| `date` | Required, `Y-m-d`, today or earlier. This is the date the marks are for, not the day the request arrives |
| `marks` | Required, at least one item |
| `marks.*.student_id` | Must be an active student of that register (`errors["marks.N.student_id"]`) |
| `marks.*.status` | `present`, `absent` or `fugitive` (`errors["marks.N.status"]`) |

The `Idempotency-Key` header is optional. The answers are:

- a submission: 200 "Register submitted";
- the same key again: 200 "Register already submitted", with the header `Idempotent-Replayed: true`. Nothing is written again.

A later submission for the same day, with a new key, is a correction. It overwrites the marks it contains.

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| Day status | The `attendance_info.dN` codes: 0 not marked, 1 `present`, 2 `absent`, 3 `leave`, 4 `holiday`, 5 `fugitive`. An unmarked day is `holiday` when it is a holiday, else `pending` |
| Holidays | Calendar holidays (`academic_calendar_info`, `event_status = 1`, `event_attendance = 0`) plus weekly off days (`clr_days.status = 0`). This is the rule of the web system's `get_working_days_info_for_range` |
| A student's days | Every one of the student's placements (not deleted), because `attendance_student_id` is the `current_status_info_id` of each year |
| `marked_by`, `marked_at` | The latest `app_attendance_register_log` row for the student's register on that date, with the teacher's name. A day marked on the web has `null` |
| `academic_year`, `years` | The current placement's session, and every session the student was in, newest first |
| `class_label` | `Class - Section` |
| `terms`, `term_id` | `[]` / `null`: the school has no terms. The report uses `from` / `to` instead, as the web profile's search does |
| `check_in`, `check_out`, `note`, `leave_reason` | `null`: the school does not record them |
| Register `section_id` | `session-class-section-group-shift` of a `class_teacher_info` row, for example `12-5-1-0-0`. A 0 means all |

## 4. Rules

### Counts and percentages (10.1, 10.3)

These follow the web profile:

- `holiday` = the number of holiday days in the range;
- `working_days` = days − holidays;
- `pending` = working days − (present + absent), never below 0;
- each percentage = its count / (present + absent + leave + fugitive + pending) × 100, to 1 place. With no days, it is 0;
- `rate` (10.1) and `percentage` (10.3) are the present percentage.

Dates after today are never counted.

### Register (10.5, 10.6)

- **Students:** the active students (`active_status = 1`) of the register's session and class. The section, group and shift are matched unless they are 0. They are sorted by roll.
- **A teacher who is not a class teacher** in a running session gets 403 "You are not a class teacher this session". A `section_id` that is not theirs is 403 "This is not your class".
- **Approved leave stays.** A student whose day is already 3 (leave) is left as leave, whatever the mark says.
- **The write**, in one transaction:
  - the mark goes into column `d<day>` of the student's `attendance_info` row for that month and year;
  - `attendance_modifier` is set to the teacher's `users.id`, and `attendance_DOM` to now;
  - if the month row does not exist, it is created the way the web month setup creates it: all days 0, with class, section, creator and modifier;
  - the submission is logged in `app_attendance_register_log`, with the marks as sent, the teacher, the key and the IP.
- It is logged as `activity:submit_attendance`, with `section_id` and `date`.

### Guardian SMS (10.6)

The web register (`attendance/attendance_update`) sends these SMS, and the app register now sends them the same way. The code is in `AttendanceSms` and `SmsGateway`; `SmsGateway` is a port of the web `Sms_lib`.

| Rule | Detail |
|---|---|
| When | After the response is sent, so the app never waits for the gateway, and a gateway error never fails the register |
| Switch | Only when `SMS_ENABLED=true` in `.env`. It is off by default, so local and test runs never send a real SMS |
| Which day | Only when `date` is today. A past register sends nothing, as on the web |
| Which students | Only those whose status **changed**. A correction with the same status sends no second SMS (contract: "one per student per day unless the status changes") |
| Which statuses | Only those with an active template, the school's latest `message_info` row with `message_status = 1` for type 6 (present), 7 (absent) or 8 (fugitive). The web teacher ticks "Send SMS?"; the app has no such field, so the school's templates decide ("as the school has configured") |
| Text | The template, with `@student_name`, `@student_id` (6 digits), `@student_class`, `@student_roll`, `@student_section`, `@attendance_status` (`PRESENT` / `ABSENT` / `FUGITIVE`) and `@attendance_date` (`dd-mm-yyyy`) filled in |
| Number | `student_info.contact_number`. An 11-digit number gets `88` in front; any other length that is not 13 or 14 with the `+` is skipped |
| Gateway | Found as on the web: the school's web token from `pathshala.cloud` by EIIN, then the credentials and the on/off status from `isms.itlab.solutions`. MIM (101, 102), Elitbuzz (103, 104) or SylSMS (105, 106). The web's built-in default key is not copied: no credentials means no SMS. SSL certificates are checked |
| Balance | Stock in `message_stock_info` (not expired), plus iSMS recharges, minus `message_receiver_info.msg_counter` since 1 January last year. Sending stops when the balance is too low |
| Log | One `message_receiver_info` row per SMS, as the web logs it: template id, gateway id (or its error), student row id, receiver type 1, SMS parts, the teacher's `users.id` as sender. The web SMS balance and reports count it. A gateway that cannot be reached sends nothing and logs nothing |

## 5. Table (run on every school database)

The write connection is `tenant_attendance`. It writes only to these:

- the day columns `d1`..`d31`, `attendance_modifier` and `attendance_DOM` of `attendance_info`, plus a new month row;
- new rows in `message_receiver_info`, the web SMS log;
- the table below.

```sql
CREATE TABLE IF NOT EXISTS `app_attendance_register_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section_id` varchar(50) NOT NULL COMMENT 'register id: session-class-section-group-shift',
  `session_code` int(10) NOT NULL,
  `class_code` int(11) NOT NULL,
  `section_code` int(11) NOT NULL,
  `group_code` int(11) NOT NULL,
  `shift_code` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `teacher_code` int(11) NOT NULL,
  `marks` longtext NOT NULL COMMENT 'submitted marks, as JSON',
  `idempotency_key` varchar(100) DEFAULT NULL,
  `created_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idempotency_key` (`idempotency_key`),
  KEY `idx_register` (`section_id`,`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

**Check before going live:** `attendance_info.attendance_id` and `message_receiver_info.msg_receiver_id` must be auto-increment on every school database, because the API inserts rows without an id. In the test school they are not.

## 6. Errors

Errors use the envelope `{ "status": false, "message": "...", "errors"?: { field: [..] } }`.

| HTTP | Cases |
|---|---|
| 401 | No token, a bad token, or a token that was logged out |
| 403 | Another student's id; not a class teacher; another teacher's `section_id`; the wrong role; a guest; a banned teacher |
| 404 | The student is not found or is deleted |
| 422 | A bad `from` / `to` / `month` / `date`; `to` before `from`; a future date on 10.6; empty `marks`; a student not in the register; a status other than present / absent / fugitive |
| 429 | More than 120 requests a minute from one IP (`throttle:attendance`) |

## 7. Tested on local (2026-11-03)

`AttendanceApiTest` runs on `pathshala_app_test_school`, with explicit ids, because the test school's `attendance_info` has no auto-increment. It covers:

- the report counting a duration the way the web profile does;
- the month with every day's status, a future month, and the month being required;
- the session summary with its monthly chart;
- another student's attendance;
- the register for a date;
- submit: the day columns and the log written, one write per key, leave not overwritten;
- a wrong student, status or date;
- only the class teacher using the register;
- guardian SMS, with a fake gateway: sent only for a changed status with a template, text and number filled in, logged in `message_receiver_info`; nothing for another day, when switched off, or without balance.

All tests pass except `HomeworkApiTest`, which fails only on XAMPP's MariaDB (a known test-setup problem).

## 8. Open items

- Guardian SMS has not gone through a real gateway yet. Test it once on the live server with one student.
- 10.4, the PDF, is the app team's. The server sends no PDF.
- Confirm with the app team that `?from` / `?to` is used instead of terms in 10.1.
- `marked_by` / `marked_at` are only known for registers submitted from the app. The web register does not record who marked a day.
