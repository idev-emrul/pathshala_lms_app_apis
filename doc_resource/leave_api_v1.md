# Leave API v1 (Module 11 Leave Application + Module 12 Early Leave)

The mobile app's leave endpoints. They follow the API contract (Modules 11 and 12) and the homework API's conventions ([hw_api_v1.md](hw_api_v1.md)): same URL base, same no-token mode, same error format.

| File | What it holds |
|---|---|
| `application/controllers/api/v1.php` | Routing, caller checks and validation (section "MODULES 11 + 12") |
| `application/models/student_leave/leave_api_model.php` | JSON shapes and the reads the web pages do not need |
| `application/models/student_leave/slv_common_model.php`, `slv_leave_model.php`, `el_leave_model.php` | The rules, shared with the web pages (numbers, overlap, attendance sync, expiry, logs, notifications) |
| `postman/Leave_API_v1.postman_collection.json` | Every endpoint with real example responses, ready to run on local |
| `leave_api_v1_sheet.xlsx` | API list for the app team |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://localhost/pathshala-multi-v3/multi-general-api-v3/api/v1` |
| Live | `<api host>/api/v1` |

**No token for now.** Every request names the school and the caller:

| Field | Header | Value |
|---|---|---|
| `database` | `X-Database` | the school database, e.g. `pathshala_borderguardsylhet` |
| `user_type` | `X-User-Type` | `student`, `guardian` or `teacher` |
| `user_id` | `X-User-Id` | student: `current_status_info_id` · guardian: mobile number · teacher: `users.id` |

GET requests put them in the query string; POST requests in the body (form or JSON). On `students/{studentId}/...` a student may leave out `user_type` / `user_id`: the student in the path is the caller.

- **Guardian = mobile number.** There are no guardian accounts yet. `student_info.contact_number` is the only link between a guardian and children, so the guardian's `user_id` is that number (`01711000000` or `8801711000000`). Every active student with that number is a linked child.
- **Teacher** covers Admin and Super Admin users too (`users.user_type`). Admin / Super Admin act as the school admin.
- A request with `Authorization: Bearer <jwt>` is handled by the token instead, exactly like the homework API. When token login is turned on (`NO_TOKEN_MODE = FALSE` in `v1.php`), these endpoints need no change. A guardian token must carry `user_type = Guardian`, `student_ids` and `guardian_mobile`.

**This mode has no login check.** Turn it off before go-live (hw_api_v1.md §3).

| Caller | May use |
|---|---|
| Student | 11.1 to 11.3, withdraw, 12.1 to 12.3, only for their own `studentId` |
| Guardian | The same for a linked child; 12.4 and 12.5 for the parent step |
| Class teacher | 11.4, 11.5 for students of sections where they are class teacher (when Leave Setup says "class teacher or office"); 12.4, 12.5 teacher step; gate |
| Admin / Super Admin | 11.4, 11.5 for the whole school; 12.4 / 12.5 final step (and the teacher step as a fallback); 12.6; gate; summaries |

The leave module must be set up for the school (web: `slv_setup/setup`). Otherwise every leave endpoint answers 503 `not_set_up`.

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 11.1 | GET | `/students/{studentId}/leave?year=&month=&type=&status=&cursor=` | Summary, leave types, applications (paged) |
| 11.2 | GET | `/students/{studentId}/leave/{applicationId}` | One application with history |
| 11.3 | POST | `/students/{studentId}/leave` | Apply (multipart) |
| proposal | POST | `/students/{studentId}/leave/{applicationId}/withdraw` | Withdraw a pending application |
| 11.4 | GET | `/leave/review?status=` | Review queue + tab counts |
| 11.5 | POST | `/leave/review/{applicationId}/decision` | Approve / reject |
| proposal | GET | `/admin/leave/summary?year=&month=` | Class-wise + leave-type-wise summary |
| 12.1 | GET | `/students/{studentId}/early-leave?year=&month=&status=` | Context, guardians, today, overview, history |
| 12.2 | GET | `/students/{studentId}/early-leave/{requestId}` | One request |
| 12.3 | POST | `/students/{studentId}/early-leave` | Create (multipart) |
| 12.4 | GET | `/early-leave/queue` | Requests waiting for the caller's step |
| 12.5 | POST | `/early-leave/{requestId}/decision` | Approve / reject the caller's step |
| 12.6 | GET | `/early-leave/register?date=` | Daily register (school admin) |
| proposal | GET | `/gate/early-leave?date=` | Approved requests of the day, for the gate |
| proposal | POST | `/early-leave/{requestId}/release` | Verify the code and release the student |
| proposal | GET | `/admin/early-leave/summary?year=&month=` | Class-wise early leave summary |

Ids are numeric strings (`"3"`). Paths accept `3` or the contract style `lv-3` / `el-3`.

**Idempotency-Key** is accepted on every POST. The first answer is stored per caller + key; the same key again returns it with `Idempotent-Replayed: true` and changes nothing.

11.1 (first page) and 12.1 are "read, cached": they return an `ETag`; send it back as `If-None-Match` to get `304`.

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| Leave type `id` | A slug of the type's English name (`sick`, `casual`, `emergency`, `family`, `other`); `name` is the Bangla name when set. 11.3 also accepts the numeric id |
| Early leave `reason` | Slug of the reason (`medical`, `family_emergency`, `personal_work`, `other`); 12.1 also sends `reasons` [{id, name}] |
| `year` (11.1 / 12.1) | Calendar year of `from` / leave date, default this year. `years` = years with applications + this year |
| `days` | Counted by the server, calendar or working days per Leave Setup |
| Status `withdrawn` (11) | Extra value, from the withdraw proposal |
| Status `cancelled` (12) | Extra value, set when a request is cancelled on the web |
| `guardians[].id` | `father`, `mother`, `guardian` (from `student_info`); `relation` in Bangla; `mobile` masked for a student |
| `pickups[]` (extra in 12.1) | Linked guardians (`g_father` …) and registered pickup people (`p_12`). 12.3 `pickup` takes one of these ids, or free text such as `"চাচা (রফিক)"` |
| `steps[]` | One per stage of that request: `parent`, `teacher`, and `final` when the school had Final Approval on at creation. A step that did not happen is `not_reached` |
| `release_code` (extra) | Sent to the guardian only, once the request is approved; the guardian shows it at the gate |
| `history[].by` for a guardian | The student's first guardian name (all guardians share one number) |

## 4. Rules (same as the web pages)

- 11.3: `from` no more than the school's "days back" before today and "days ahead" after; at most the school's max days; no overlap with a pending or approved application (`fields.from`); one file, PDF / JPG / PNG, size limit from setup (`fields.attachment`). The class teachers, the office and the guardian are notified.
- 11.5: reject needs remarks; already decided is 422; another section's student is 403. Approved days are written as Leave (3) into `attendance_info` (only empty or absent cells), unless switched off in setup.
- 12.3: leave date today or up to the school's advance days, not a weekly off day, before that shift's closing time, and for today at least the school's lead minutes from now (`fields.leave_at`). `guardian_id` must be a linked guardian. One open request per student per day. A guardian creating it skips the parent step (`pending_teacher`).
- 12.5: the server picks the step from the caller and the status. Remarks are optional. Reject closes the request and later steps stay `not_reached`. The last approval creates the release code and notifies the guardian.
- Pending early leave past its leave time becomes `expired` on the next early leave call (FR-12.11).
- Release: only an `approved` request on its leave day; `code` must match when the school checks codes; a different person in `handed_to` needs the setup option and `override_reason`.
- Every action is logged (`sl_logs`, append only) and the notification text is queued in `sl_notifications` (the SMS / push sender is not built yet).

## 5. Errors

Same as hw_api_v1.md §4: `{ "error": { "code", "message", "fields"? } }`.

| HTTP | `code` | Leave examples |
|---|---|---|
| 400 | `bad_request` | `user_type` not student / guardian / teacher |
| 401 | `unauthorized` | No `database`; unknown `user_id`; no student has that guardian mobile |
| 403 | `forbidden` | Another student's id; child not linked to the guardian; not the class teacher; a student calling a reviewer endpoint |
| 404 | `not_found` | Application / request not found, or belongs to another student |
| 405 | `method_not_allowed` | Wrong method |
| 409 | `conflict` | Same Idempotency-Key while the first request is still running |
| 422 | `validation_failed` | Any rule above; `fields` names the field |
| 503 | `not_set_up` | Leave tables are not created for this school |

## 6. Tested on local (2026-10-23)

All endpoints were run on `pathshala_borderguardsylhet`; the Postman collection runs top to bottom (21 of 22 pass on a Friday, when `leave_at` falls on tomorrow and the gate correctly refuses to release a request that is not for today). The test rows were deleted afterwards.

Also fixed in `v1.php` while adding these: `Authorization: Bearer <school_db_name>` (§3.1 of hw_api_v1.md) called a missing `tokenFromDbName()` and crashed; it now works for every endpoint. Guardians (`user_type=guardian`) now also work on the homework and quiz student endpoints.
