# Pathshala App API

Laravel 12 API-only backend for the Pathshala mobile app. It has login for students and teachers, and the homework module for both. It has its own code but uses the same databases as the live Pathshala project.

Last updated: 2 November 2026.

## Status

| Area | State |
| --- | --- |
| Student login | Built |
| Teacher login | Built, verified with one real account up to the PIN step |
| Homework API (student and teacher) | Built, 14 routes |
| Activity log | Built, monthly files, changes only |
| Tests | 80 passing at the last run |
| Other 3 roles, SMS gateway, cloud uploads | Not built |

## Commands

```bash
npm run dev                  # runs php artisan serve, http://127.0.0.1:8000
php artisan test             # uses test databases only
php artisan migrate          # master database only
php artisan activity:read 2026 11 --user=1
php artisan otp:prune
```

`package.json` exists only so that `npm run dev` starts the server. There is no frontend build.

## Safety rules

- **Never run `migrate:fresh` or `db:wipe` on the master database.** They would drop `school_information` and the other tables owned by the live project.
- `db_school_api_web` already has an unrelated `migrations` table. This project tracks its own in `app_migrations`.
- Migrations only ever run on the master database, never on a school database.
- The school connection `tenant` is read-only. Only homework services write to a school, through `tenant_homework`, and only to `hw_` tables. This is a code rule, not a database permission.
- Never write test data to a real school. `TestCase` refuses any master database except `pathshala_app_test`.
- Never put secrets in a response or in a readable token field: `student_auth`, `users.password`, PIN, OTP, `db_name`, `api_code`, `school_secret`, `TOKEN_SALT`, `JWT_SECRET`.
- `--pretend` on `php artisan migrate` still creates the migration table if it is missing.

## Databases

**Master: `db_school_api_web`**

| Table | Owner | Use |
| --- | --- | --- |
| `school_information` | Live project, read-only | School list, `db_name` |
| `app_user` | This project | One row per mobile number and role |
| `app_user_log` | This project | `login`, `logout`, `pin_reset`, `number_reset` |
| `app_login_attempt` | This project | OTP records |
| `app_migrations` | This project | Migration tracking |

**School databases**

One per school. The name comes from `school_information.db_name`. Test school: `schl_id` 8, database `pathshala_borderguardsylhet`.

| Table | Use |
| --- | --- |
| `student_info` | Student ID, `student_auth` (plain text password), `contact_number` |
| `student_current_status_info` | Class placement. `current_status_info_id` is the homework student id |
| `users` | Teacher login: `username`, `password` (`$2a$` bcrypt), `user_type`, `activated`, `banned` |
| `teacher_info` | Teacher profile. Some logins have `teacher_code` 0 and no row here |
| `subject_teacher_info` | What a teacher teaches. `section_code` 0 means every section |
| `session_info`, `class_info`, `section_info`, `shift_info`, `group_info`, `subject_info` | Lookups |
| `institute_info` | `institute_EIIN` (upload folder), `institute_storage_path` (cloud base URL) |
| `hw_*` | Homework. See `doc_resource/hw_assignment_tables.md` |

Data traps:

- `class_code` and class name do not match. In the test school, class Six is `class_code` 5 and class Five is `class_code` 6.
- Several sessions can have `running_session = 1` at once. The test school has 5.

## Folder structure

```text
app/Http/Controllers/
  SchoolController.php
  Auth/            AuthController, StudentAuthController, TeacherAuthController
  HwAssignment/    StudentHomeworkController, TeacherHomeworkController
app/Http/Middleware/
  AuthenticateAppToken    jwt.app: checks the token
  ConnectTenant           tenant: opens the school database
  AuthorizeHomework       homework:student or homework:teacher
  RecordActivity          activity:action_name
app/Services/
  OtpService, SchoolDbService, StudentAuthService, TeacherAuthService, TokenService, ActivityLogService
  HwAssignment/    HomeworkReader, SubmissionService, AssessmentService, AssignmentService,
                   IdempotencyService, HomeworkFiles, HomeworkTime, HomeworkCaller
doc_resource/      Plans from the web system. hw_api_v1.md is the homework contract
postman/           Pathshala_App_API (student login), Pathshala_Homework_API (login and homework)
public/auth-test.html   Test UI. Delete before going live
tests/Feature/     OtpRulesTest, StudentLoginFlowTest, TeacherLoginFlowTest, HomeworkApiTest
```

`StudentAuthService` also holds the shared login code: set PIN, PIN login, logout, profile and the number checks.

## Conventions

- Every response is `{ "status": true, "message": "...", "data": {} }`. Errors have `status: false`, and `errors` for bad fields. Use `ApiResponse`.
- Throw `ApiException` for expected failures. Do not expose SQL or database names.
- `doc_resource/hw_api_v1.md` uses a different envelope, `{ "data": ... }` and `{ "error": ... }`. This project keeps its own envelope, and the fields inside `data` follow that doc.
- Homework ids are strings. Dates in the API are UTC, and the database stores `Asia/Dhaka`. Use `HomeworkTime`.
- Folder and namespace names are StudlyCase, because the live server may be case-sensitive.

## Login

**Common start:** select a role, `POST /auth/send-otp`, then `POST /auth/verify-otp`. Each later step returns a `flow_token` for the next, so steps cannot be skipped. Flow tokens cannot cross roles.

**Student**

1. `POST /auth/student/check` with school, class and Student ID. A wrong ID or wrong class gives 404 "The information you entered is not valid", with a guest option.
2. Verify by either `POST /auth/student/verify-password` or the contact OTP (`send-contact-otp`, `verify-contact-otp`).
3. If neither works, `POST /auth/guest`.
4. `POST /auth/set-pin` returns the JWT.

**Teacher**

1. School from the list: `POST /auth/teacher/verify` with `schl_id`, `username`, `password`.
2. "Others": `POST /auth/teacher/guest` with `school_name`. No username or password is needed.
3. `POST /auth/set-pin` returns the JWT.

A teacher login must have `user_type = Teacher`, `activated = 1` and `banned = 0`. A linked `teacher_info` row must be active, but the row is optional. The name falls back to `users.user_full_name`. Passwords use `password_verify`, because `Hash::check` refuses `$2a$` hashes.

**After login:** `POST /auth/pin-login`, `GET /auth/me`, `POST /auth/logout`.

### One account per number and role

- `app_user` has a unique rule on `mobile_number` + `role`. One number can have one teacher and one student.
- The row is created at OTP verify, with an empty `pin`. It is filled at Set PIN.
- `send-otp` on a number with a PIN returns 409 and `data.next = "pin_login"`. No OTP is sent.
- Forgot PIN: `send-otp` with `"reset_pin": true`, then the same steps.
- A verified number can only be re-verified by the same person. Another person or a guest gets 409 and the account stays as it is. A guest number can later become verified.
- `verified_id` is the student's registration code, or the teacher's `users.id`. These can overlap, so always check the role.

### OTP

`OTP_FIXED=123456` for now, with `OTP_DRIVER=log`. Valid for 30 minutes, 2 sends, 2 wrong entries, then a 1-hour lock. The record is per number and purpose, not per role. **Remove `OTP_FIXED` before going live.**

### Token

The JWT has no expiry. It is signed with HS256, with `JWT_SECRET` + `TOKEN_SALT` as the key. Payload: `typ`, `jti`, `sub` (`app_user.id`), `role`, `iat`, `db`, `schl`.

- Only `db` and `schl` are encrypted, with `APP_KEY`. The rest can be read by anyone but not changed.
- `TOKEN_SALT` is one secret shared by all users, kept in `.env`. It never goes in a token.
- A token works only while it equals `app_user.auth_token`. Logout clears it, and a PIN reset replaces it.
- `ConnectTenant` decrypts `db` to open the school database. `schl` is not read anywhere.
- Changing `JWT_SECRET`, `TOKEN_SALT` or `APP_KEY` stops every token.

## Homework API

Base: `/api/v1`. Bearer token required. Verified users only, so guests get 403. Teacher logins are re-checked on every homework request, so a banned teacher gets 403 at once.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/students/{id}/homework` | List, with ETag |
| GET | `/students/{id}/homework/summary` | Summary, with ETag |
| POST | `/students/{id}/homework/{homeworkId}/submissions` | Hand in text, link or files |
| GET | `/teacher/teaching-options` | Classes, sections, subjects |
| GET | `/teacher/homework/filter-options` | Filter values |
| GET | `/teacher/homework/dashboard` | Totals and recent |
| GET, POST | `/teacher/homework` | List, create and publish |
| GET, PATCH, POST, DELETE | `/teacher/homework/{id}` | One homework, edit (POST for files), soft delete |
| GET | `/teacher/homework/{id}/submissions` | Roster |
| GET | `/teacher/homework/{id}/submissions/{studentId}` | One student's work |
| POST | `.../submissions/{studentId}/assessment` | Marks |
| POST | `.../submissions/{studentId}/resubmission` | Ask for a new version |

- The student `{id}` is `current_status_info_id`. The app gets it from `/auth/me` as `placement.homework_student_id`.
- A student can only use their own id. Another teacher's homework returns 404.
- A teacher sees homework they created, or where `teacher_id` is their `teacher_code`. There are no admin features, because admins cannot log in to the app.
- A teacher can only create for classes, sections and subjects in `subject_teacher_info`. Without a `teacher_code`, the options are empty.
- Create uses the newest running session that has active students in the class.
- `Idempotency-Key` on create returns the first answer. It uses `hw_api_idempotency`.
- Business rules are in `doc_resource/hw_api_v1.md` and `doc_resource/hw_submission_plan.md`.

### Files

- Uploads go to the disk in `HW_FILES_DISK`, default `public`, which needs `php artisan storage:link`.
- Paths match the web system: `<EIIN>/homework/assignment|submission/<file>`.
- A file on the local disk gets a local URL. Otherwise the URL is `institute_storage_path` + path, so files from the web still open.

## Activity log

File: `storage/logs/activity/activity_YYYY_MM.log`, one JSON line per event, one file per month.

Only changes are logged, not views: `create_homework`, `update_homework`, `delete_homework`, `submit_homework`, `assess_homework`, `request_resubmission`. To log a new route, add `->middleware('activity:action_name')`. Failed requests and replayed creates are not logged.

```json
{"time":"2026-11-02 14:10:00","app_user_id":1,"role":"teacher","action":"create_homework","message":"Hasan created homework: Algebra","details":{"homeworkId":"3"},"ip_address":"127.0.0.1"}
```

Never log answers, marks, feedback, passwords, tokens or `db_name`. Login, logout and PIN reset go to the `app_user_log` table instead.

## Environment

`.env` needs `APP_KEY`, the `DB_*` values, `JWT_SECRET`, `TOKEN_SALT`, `OTP_DRIVER`, `OTP_FIXED`, and optionally `FLOW_TOKEN_TTL`, `HW_FILES_DISK`, `HW_FILES_URL`, `ACTIVITY_LOG_PATH`.

Rate limits: auth routes 30 a minute, homework routes 120 a minute, both per IP. Wrong passwords and PINs: 5 attempts, then a 15-minute wait.

## Tests

- `pathshala_app_test` is the master test database.
- `pathshala_app_test_school` is created by `HomeworkApiTest`, with the structure of the real tables and made-up rows. All homework writes in tests go here.
- Login tests read real school data through the read-only connection, and write nothing to it.
- Real teacher passwords are unknown, so the login test uses a stand-in account with a `$2a$` hash.
- Run the full suite after any change.

## Test UI

`public/auth-test.html` at `http://127.0.0.1:8000/auth-test.html` covers student login, teacher login and the teacher homework panel, with an API log. There is no student homework panel yet, so use Postman for hand-ins. Reloading the page loses the token. It calls the real API, so anything created there is real data.

## Before going live

1. Remove `OTP_FIXED` and add an SMS gateway.
2. Delete `public/auth-test.html`.
3. Add cloud bucket credentials. Until then the web system cannot see files uploaded here.
4. Use a long random `TOKEN_SALT`. The current one is short and has been shared in chat.
5. Change the password of `idev_emrulbd`, which was shared in chat. Its `user_type` was changed to `Teacher`, so check whether it should be Admin.
6. Limit the database user. The `hw_`-only write rule is only in code.

## Open items

- `GET /auth/me` still works for a banned teacher, because that check runs only on homework routes.
- `auth_token` is stored in plain text. Hashing it is recommended, since tokens never expire.
- `schl` in the token is unused and can be removed.
- Teacher login does not check the number against `teacher_info.contact_number`.
- `send-otp` reveals whether a number has an account.
- A row with an empty `pin` stays forever if the user stops before Set PIN. No cleanup job exists.
- Activity files are never removed, and are separate on each server.
- Confirm the session rule for create, since several sessions can be running.
- The app team must confirm the response envelope against the homework doc.

## Out-of-date docs

These do not yet match the code:

- `pathshala_app_authentication.md` and `.docx`: per-user salt, "Forgot password", "Verification failed", no teacher or homework.
- `README.md`: says Phase 1 only, and that the API cannot write to school databases.
- `postman/Pathshala_App_API.postman_collection.json`: student flow only, no `reset_pin`.
