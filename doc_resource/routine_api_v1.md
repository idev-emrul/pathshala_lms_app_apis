# Class Routine API v1 (Module 3)

These are the class routine endpoints for the mobile app. They follow *PATHSHALA Apps — API Contract v1, Module 3* (`PATHSHALA_API_v1.pdf`), and they are built the same way as the homework API. They use this project's envelope, `{ status, message, data }`. They only read the routine and never write it.

| File | What it holds |
|---|---|
| `app/Http/Controllers/ClassRoutine/StudentRoutineController.php` | 3.1: checks the date, finds the caller's placement |
| `app/Http/Controllers/ClassRoutine/TeacherRoutineController.php` | 3.2: checks the date and that the teacher id is the caller's own |
| `app/Services/ClassRoutine/RoutineReader.php` | The queries and the JSON shape, plus the off-day and holiday checks |
| `app/Services/ClassRoutine/RoutineCaller.php` | Who is calling, built by the middleware |
| `app/Http/Middleware/AuthorizeRoutine.php` | `routine:student` / `routine:teacher` |
| `tests/Feature/RoutineApiTest.php` | Feature tests |
| `postman/Pathshala_Routine_API.postman_collection.json` | Login, then both endpoints |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://127.0.0.1:8000/api/v1` |
| Live | `<api host>/api/v1` |

Every request needs `Authorization: Bearer <token>`. The app gets the token from `POST /auth/set-pin` or `POST /auth/pin-login`. The token decides the school and the caller. Ids in the URL or body never decide them.

| Caller | May use |
|---|---|
| Student (verified) | 3.1, only with one of their own `current_status_info_id`s |
| Teacher (verified, `users.user_type = Teacher`, activated, not banned) | 3.2, only with their own `app_user.id` |
| Guest, or the other role | 403 |

The teacher account is checked again on every request, so a teacher who is banned loses access at once.

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 3.1 | GET | `/students/{studentId}/routine?date=YYYY-MM-DD` | The student's periods on one date |
| 3.2 | GET | `/teachers/{teacherId}/routine?date=YYYY-MM-DD` | The teacher's periods on one date |

- `{studentId}` is `student_current_status_info.current_status_info_id`. The app gets it from `GET /auth/me` as `placement.homework_student_id`.
- `{teacherId}` is the caller's `app_user.id`, which comes back as `user.id` from Set PIN and from `/auth/me`. Any other id gets 403.
- `date` is required, in the form `Y-m-d`.

### 3.1 response

```json
{
  "status": true,
  "message": "Routine",
  "data": [
    {
      "id": "12",
      "type": "lesson",
      "start_time": "09:00",
      "duration_minutes": 45,
      "title": "Bangla",
      "teacher_name": "Rahima Khatun",
      "room": "Room 101",
      "status": null,
      "status_reason": null
    }
  ]
}
```

### 3.2 response

This is the same as 3.1, except that it has `class_label` and `section` in place of `teacher_name`:

```json
{ "id": "12", "type": "lesson", "start_time": "09:00", "duration_minutes": 45, "title": "Bangla",
  "class_label": "Six", "section": "A", "room": "Room 101", "status": null, "status_reason": null }
```

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| Period `id` | `clr_periods.period_id`, as a string |
| `start_time`, `duration_minutes` | `clr_periods.start_time` / `end_time`. When the end is not after the start, the duration is `null` |
| `title` | `subject_group.subject_group_name` (from `clr_routines.subject_group_code`) |
| `teacher_name` | `teacher_info.teacher_name` (from `clr_routines.teacher_code`) |
| `room` | `clr_class_rooms.class_room_name` |
| `class_label`, `section` | `class_info.class_name`, `section_info.section_name` |
| `type` | Always `lesson`. The school stores no breaks |
| `status`, `status_reason` | Always `null`. The school has no per-date changes, such as a cancelled class or a substitute |

## 4. Rules

- The routine is the weekly timetable in `clr_routines` (`status = 1`), for the weekday of `date`. Only active periods are included (`clr_periods.status = 1`). Periods are sorted by start time.
- **Weekly off day:** if the weekday's `clr_days.status` is 0, the answer is `[]`.
- **Holiday:** if `academic_calendar_info` has an event with `event_status = 1` and `event_attendance = 0` that covers the date, the answer is `[]`.
  - For a student, the event counts when its `student_class` is empty, `All` or `0`, or when it lists the student's class code or class name.
  - For a teacher, only school-wide events count.
- **Student:** the routine rows match the placement's session and class. A routine row whose section, group, shift or medium is 0 is for everyone in the class.
- **Teacher:** every routine row with the teacher's `teacher_code`, in every running session (`session_info.running_session = 1`). A teacher login without a `teacher_code` gets `[]`.

## 5. Errors

Errors use this project's envelope, `{ "status": false, "message": "...", "errors"?: { field: [..] } }`.

| HTTP | Cases |
|---|---|
| 401 | No token, a bad token, or a token that was logged out |
| 403 | Another student's id or another teacher's id; the wrong role; a guest; a banned or deactivated teacher |
| 422 | `date` is missing or not a real `Y-m-d` date (`errors.date`) |
| 429 | More than 120 requests a minute from one IP (`throttle:routine`) |

## 6. Tested on local (2026-11-03)

`RoutineApiTest` runs on the copied test school, `pathshala_app_test_school`. It covers:

- the time order;
- a weekly off day, a holiday, and another class's holiday;
- a period with no duration;
- the date check;
- access to another student's or teacher's routine;
- the wrong role, and guests.

The full suite passes.

## 7. Open items

- Confirm with the app team that `{teacherId}` is `app_user.id`. The contract does not say which id it means.
- There are no breaks and no per-date status, because the web routine has none. If the school starts recording them, `type` and `status` need a source.
