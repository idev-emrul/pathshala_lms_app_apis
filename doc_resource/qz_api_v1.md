# Quiz API v1 (Module 13: Quiz Acceptance and Assessment)

The mobile app's quiz endpoints. They follow the API contract (Module 13) and the homework API's conventions ([hw_api_v1.md](hw_api_v1.md)): same URL base, same no-token mode, same error format.

| File | What it holds |
|---|---|
| `application/controllers/api/v1.php` | Routing and validation for 13.1 to 13.10 (section "MODULE 13: QUIZ") |
| `application/models/qz_api_model.php` | JSON shapes and the few reads the web pages do not need |
| `application/models/qz_quiz_model.php`, `qz_student_model.php` | The quiz rules, shared with the web pages (accept, attempt, scoring, marks, release) |
| `postman/Quiz_API_v1.postman_collection.json` | Every endpoint, ready to run on local |
| `qz_api_v1_sheet.xlsx` | API list for the app team |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://localhost/pathshala-multi-v3/multi-general-api-v3/api/v1` |
| Live | `<api host>/api/v1` |

Without a token (testing mode, see hw_api_v1.md §3), every request sends `database`. Teacher requests also send `user_id` (the teacher's `users.id`). GET requests put them in the query string; POST and PUT put them in the body (the query string works too). With a Bearer token, the token decides.

| Caller | May use |
|---|---|
| Student | 13.1 to 13.5, only for their own `studentId` |
| Guardian | 13.1 and 13.2 for a child; 13.3 to 13.5 get 403 |
| Teacher | 13.6 to 13.10 for quizzes they created, or that name them as teacher |
| Admin, Super Admin | 13.6 to 13.10 for every quiz; may create a quiz for any teacher (`teacher_id`) |

The quiz module must be set up for the school (web: `qz_setup/setup`). Otherwise every quiz endpoint answers 503 `not_set_up`.

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 13.1 | GET | `/students/{studentId}/quizzes?year=&month=&status=` | Summary + quiz list |
| 13.2 | GET | `/students/{studentId}/quizzes/{quizId}` | One quiz, with result when released |
| 13.3 | POST | `/students/{studentId}/quizzes/{quizId}/accept` | Accept the quiz |
| 13.4 | POST | `/students/{studentId}/quizzes/{quizId}/attempt` | Start or resume an in-app attempt; returns the questions |
| 13.5 | POST | `/students/{studentId}/quizzes/{quizId}/attempt/submit` | Submit answers |
| 13.6 | GET | `/teacher/quizzes` | The teacher's quizzes + classes and subjects for the create form |
| 13.7 | GET | `/teacher/quizzes/{quizId}/roster` | Every student's acceptance, score and feedback |
| 13.8 | POST | `/teacher/quizzes` | Create and publish a quiz |
| 13.9 | PUT | `/teacher/quizzes/{quizId}/marks` | Save marks and feedback |
| 13.10 | POST | `/teacher/quizzes/{quizId}/release` | Release results to students |

`studentId` is `current_status_info_id`. Ids are numeric strings (`"3"`), not the contract's examples (`"qz-3"`).

**Idempotency-Key** is accepted on 13.3, 13.5, 13.8, 13.9 and 13.10. The first answer is stored per caller + key; sending the same key again returns it with `Idempotent-Replayed: true` and changes nothing. 13.3 and 13.5 are also safe to repeat without a key (accepting or submitting twice returns the quiz as it is).

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| `due_at` (student and teacher objects, 13.8) | The **acceptance deadline** (`accept_deadline`; a reopened student gets their own). The time the quiz itself closes is the extra field `quiz_due_at`. 13.8 takes it as optional `ends_at` (default = `due_at`) |
| `acceptance` | `pending` (also a teacher reopen), `accepted`, `not_accepted` (also exempted) |
| `attempt` | `null`, `in_progress`, `submitted` (time-out submissions count as `submitted`) |
| `class_id` in 13.6 / 13.8 | `"12"` = class 12, every section. `"12-4"` = class 12, section 4. A teacher gets the whole-class key only when they teach every section |
| `subject` in 13.8 | A subject name of that class (as listed in `subjects_by_class`), or send `subject_id` instead. Names repeat across classes, so the class decides |
| `remarks` | The school's band label (Bangla when set, else English) from `perf_grading_bands` |
| `correct` / `chosen` (review), answers in 13.5 | Zero-based option indexes. A question with more than one right answer (`type: mcq_multi`, from the web question bank) uses arrays: `"correct": [0, 2]`, answer `[0, 2]` |
| Timer | `ends_at` = start + `duration_minutes`, but never after the quiz closes. A submit up to **30 seconds** after `ends_at` is accepted |

Questions created through 13.8 are also saved to the school's question bank as the teacher's private questions, as the web form does.

## 4. Student side

### 13.1 Quiz report

| Query | Notes |
|---|---|
| `year` | `YYYY` of the quiz's due time. Left out = this year. `years` lists every year the student has quizzes in |
| `month` | 1 to 12 |
| `status` | `pending`, `accepted`, `not_accepted` |

The summary uses the same filter as the list. Marks, average, best and lowest count released results only (and Not Accepted quizzes as 0% when the school's policy says so). The list is sorted: pending first by deadline, then newest.

### The student quiz object (13.1 items, 13.2, 13.3, 13.5)

Contract fields: `id`, `title`, `subject`, `class_label`, `instructions`, `total_marks`, `due_at`, `acceptance`, `accepted_at`, `mode`, `duration_minutes`, `question_count`, `attempt`, `result`.

Extras: `quiz_due_at` (when the quiz closes), `teacher` (name), `attempts_left` (in-app).

`result` is `null` until the teacher releases it. Then: `obtained`, `total`, `percent`, `remarks`, `feedback`, and for in-app quizzes `review[]` (`question_id`, `text`, `options`, `correct`, `chosen`, plus extras `marks`, `obtained`, `explanation`).

### 13.3 Accept

No body. Errors: deadline passed → 422 (the quiz now shows `not_accepted`); not open → 422; guardian → 403; inactive student → 403.

### 13.4 Start or resume

No body. Allowed only when `acceptance` is `accepted`, `mode` is `in_app`, the quiz is open, and attempts are left; otherwise 422 with the reason. Calling it again during an attempt returns the same `ends_at` and questions. Never contains the answer key.

Extras on each question: `type`, `negative_marks`, `selected` (what was saved so far). Extras on the attempt: `attempt_no`, `seconds_left` (by the server clock).

### 13.5 Submit

```json
{ "answers": { "1": 1, "2": 0 } }
```

Question ids come from 13.4. Unanswered questions are left out. An unknown question id or an option index out of range → 422, with each bad id in `fields`, and nothing is saved. A submit after `ends_at` + 30 seconds closes the attempt without these answers and returns 422. The score is calculated at once (it shows in 13.7 as `auto_score`), but `result` stays `null` until release.

## 5. Teacher side

### 13.6 Teacher's quizzes

Optional filters: `class_id` (`12` or `12-4`), `subject_id`, `status` (`draft`, `published`, `released`, `closed`, `cancelled`), `search` (title).

`classes`: key → label (`"12-4": "Twelve D"`). `subjects`: subject names. Extra `subjects_by_class`: `{ "12": [ { "id": "143", "name": "Bangla" } ] }`.

### The teacher quiz object (13.6, 13.7, 13.8)

Contract fields: `id`, `title`, `subject`, `class_label`, `due_at`, `mode`, `total_marks`, `accepted`, `pending`, `not_accepted`, `evaluated`, `released`, `average_percent`, `highest_percent`, `lowest_percent`, `acceptance_rate`.

- `evaluated` = students the teacher has marked (13.9). In-app scores that are not yet confirmed are counted in the extra `scored`.
- The percentages cover scored students (and Not Accepted as 0% when the school's policy says so).

Extras: `status`, `quiz_due_at`, `question_count`, `targeted`, `scored`, `duration_minutes`, `teacher` `{id, name}`, `created_by` `{id, name}`.

### 13.7 Roster

`entries[]`: `student_id`, `name`, `roll`, `acceptance`, `submitted`, `auto_score`, `obtained`, `feedback`, `remarks`. `obtained`, `feedback` and `remarks` stay `null` until the teacher saves marks. Extras: `section`, `percent`, `released`, `in_progress`. Ordered by section, then roll.

### 13.8 Create and publish

| Field | Required | Notes |
|---|---|---|
| `title` | yes | Up to 255 characters |
| `subject` or `subject_id` | yes | From 13.6 for that class |
| `class_id` | yes | A key from 13.6 `classes` |
| `instructions` | no | |
| `total_marks` | yes | |
| `due_at` | yes | Acceptance deadline, in the future |
| `mode` | yes | `offline` or `in_app` |
| `duration_minutes` | in_app | 1 to 600 |
| `questions[]` | in_app | `text`, `options` (2 to 6), `correct` (index, or array of indexes), `marks`; optional `negative_marks`, `explanation`. Marks must add up to `total_marks`. Leave out for offline |
| `ends_at` | no (extra) | When the quiz closes. Default `due_at` |
| `start_at` | no (extra) | Students cannot start before this |
| `max_attempts` | no (extra) | 1 to 10, default from quiz settings |
| `shuffle_questions`, `shuffle_options` | no (extra) | `true` / `false` |
| `result_release` | no (extra) | `manual` or `auto_after_due` (default from quiz settings) |
| `teacher_id` | no (extra) | Admin only: `teacher_code`. A Teacher's quiz is always their own |

The quiz goes to the active students of that class (and section) in the running session, and is published at once (students get a `quiz_received` notification event). No active students → 422. Response: 201 with the teacher quiz object.

### 13.9 Save marks

```json
{ "marks": [ { "student_id": "4723", "obtained": 3, "feedback": "ভালো করেছ।" } ] }
```

Send JSON (PUT has no form body). Every row is checked first; if any row is wrong (unknown student, did not accept, `obtained` outside 0 to `total_marks`, feedback over 2,000 characters) → 422 with each row in `fields`, and **nothing** is saved. Response: the roster.

### 13.10 Release

No body. Releases every scored, accepted student not yet released (teacher marks, or the in-app auto score). Nothing to release → 422, unless the quiz was released before (then 200). Response: the roster with `quiz.released: true`.

## 6. Errors

Same format as homework: `{ "error": { "code", "message", "fields"? } }`.

| HTTP | code | When |
|---|---|---|
| 400 | `bad_request` | Body is not valid JSON, Idempotency-Key too long |
| 401 | `unauthorized` | No token and no `database` / `user_id` |
| 403 | `forbidden` | Guardian on 13.3 to 13.5, another student's id, inactive student, Teacher sending another `teacher_id` |
| 404 | `not_found` | Unknown path, quiz not given to this student, another teacher's quiz |
| 405 | `method_not_allowed` | Wrong method (`Allow` header lists the right one) |
| 409 | `conflict` | Same Idempotency-Key still running, two attempts started at once |
| 422 | `validation_failed` | Rule broken (deadline passed, accept first, marks out of range ...) |
| 503 | `not_set_up` | Quiz module not set up for this school |

## 7. Not built yet (proposals in the contract)

| Item | Note |
|---|---|
| `GET /teacher/quizzes/summary?class_id=&subject=` | Fields not specified in the contract |
| Push notifications / `/api/v1/notifications` with `category: "quiz"` | Events are written to `qz_notification_events`; nothing sends them yet |
| `quiz_id` on the live class object | Belongs to the live class module |

## 8. Test results (local, 2026-10-14)

Admin (user 1) created an in-app quiz (id 3) for teacher_code 7 and an offline quiz (id 2) for class Twelve, section D; student 4723 took both.

| Check | Result |
|---|---|
| 13.8 bad fields | 422, every field listed |
| 13.8 in-app, then the same Idempotency-Key | 201, replay returns quiz 3, one quiz created |
| 13.4 before accepting / offline quiz | 422 "Accept the quiz first." / "held in class" |
| 13.3 twice | 200, accepted |
| 13.4 twice | Same `ends_at`, no answer key |
| 13.5 bad ids / indexes | 422 per question; then valid submit → `attempt: submitted`, `result: null` |
| 13.4 after submit | 422 "All 1 attempt(s) are used." |
| Teacher 1709 (assigned) / 1703 (not) | Sees quiz 3 / 404 |
| 13.9 not-accepted student, out of range | 422, nothing saved |
| 13.9 → 13.10 → 13.2 | `result` 3 / 4, 75%, "ভালো", feedback, review with `correct` / `chosen` |
| 13.1 summary | Totals and average over released quizzes |
| GET on 13.9 | 405, `Allow: PUT` |
| Homework endpoints | Unchanged |

The test quizzes 2 and 3 ("API TEST") are in the local database.
