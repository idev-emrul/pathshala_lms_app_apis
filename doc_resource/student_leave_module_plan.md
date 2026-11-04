# Student Leave (Module 11) + Early Leave (Module 12)

> Reference for the two modules. Status: **web pages built and tested on local DB (2026-10-18)**. Mobile app API not built yet.
>
> - Design (UI, flow, tables): https://claude.ai/artifact/AEY6dDJKXNkV4Wm6jr4bUD (shows the first 17-table draft; the built version uses the lean 9 tables below)
> - SQL: `application/sql/student_leave_module_schema.sql`

## 1. What it does

- **Module 11, Leave Application:** student or guardian applies for one or more days off → class teacher or office approves / rejects (remarks required on reject) → approved days are written as `3` (Leave) into `attendance_info.dN`.
- **Module 12, Early Leave:** request to leave before closing time → parent approves → class teacher approves → (optional) office final approval → release code to guardian → gate checks code + pickup person and records the release.

Students and guardians have no web login, so on the web **staff open a student and act as the student or as the guardian** ("Acting as" switch). Every action is saved with `via = proxy_web` and the staff user id, so the history shows "Guardian (entered by …)".

## 2. Files

| Kind | Path |
|---|---|
| SQL | `application/sql/student_leave_module_schema.sql` |
| Controllers | `application/controllers/student_leave/` `slv_setup.php`, `slv_leave.php`, `slv_review.php`, `el_leave.php`, `el_review.php` |
| Models | `application/models/student_leave/` `slv_common_model.php` (shared), `slv_leave_model.php` (M11), `el_leave_model.php` (M12) |
| Views | `application/views/admin/student_leave/` `setup`, `studentList`, `myLeave`, `reviewQueue`, `leaveReport`, `earlyLeave`, `elApprovals`, `elGate`, `elRegister`, partials `_style`, `_scripts` |
| Routes | block "Student Leave" in `application/config/routes.php` (URLs stay `slv_xxx/method`, `el_xxx/method`) |
| Menu | "Student Leave" block in `application/views/admin/include/admin_left_sidebar.php` |

Models start with the fixed boilerplate (lines 1–191 of `certificate_em_model.php`). Controllers start with the standard constructor/`is_authentic()` block and load `student_leave/...` models.

## 3. Pages (open through the client: `.../pathshala-web/information/index/<url>`)

| URL | Who | What |
|---|---|---|
| `slv_setup/setup` | Admin | create tables, rules, leave types, early leave reasons, closing time per shift/day |
| `slv_leave/studentList` | Admin, Teacher | pick a student → open Leave or Early Leave as student or guardian |
| `slv_leave/myLeave/{student_id}/{student\|guardian}` | Admin, Teacher | Fig 11.1: cards, apply form, donut, list, details, withdraw |
| `slv_review/queue` | Admin (all), class teacher (own sections) | approve / reject; admin "Correct" approved ⇄ rejected with reason |
| `slv_review/report` | Admin, Teacher | list + class-wise + leave-type-wise summary |
| `el_leave/myEarlyLeave/{student_id}/{student\|guardian}` | Admin, Teacher | Fig 12.1: context strip, request form, today's stepper, month cards, history, guardian approve, pickup people |
| `el_review/approvals` | Admin, class teacher | teacher step (after parent), final step (admin) |
| `el_review/gate` | Admin, Teacher | today's approved list, check code + pickup, release |
| `el_review/register` | Admin, Teacher | daily register + class-wise summary |

## 4. Tables (9, all in the tenant DB)

| Table | Purpose |
|---|---|
| `sl_settings` | one row: M11 rules (`slv_*`), M12 rules (`el_*`), weekly off days, attachment rules |
| `sl_lookups` | `kind = leave_type` (Sick, Casual, Emergency, Family, Other) and `kind = el_reason` (Medical Appointment, Family Emergency, Personal Work, Other) |
| `sl_logs` | append-only history for both modules (`module` + `ref_id`) |
| `sl_notifications` | queue for SMS / in-app messages (`status = queued`) |
| `slv_applications` | leave applications with class snapshot; `LV-YYYY-MM-DD-NNN` |
| `slv_attendance_sync` | each attendance cell changed, with the old value (for undo); `waiting_month` when the month sheet does not exist yet |
| `el_requests` | early leave requests with parent / teacher / final step columns and the release record; `EL-YYYY-MM-DD-NNN` |
| `el_closing_times` | closing time per shift, optionally per weekday |
| `el_authorized_pickups` | extra pickup people (linked guardians are always allowed) |

Existing tables read: `student_current_status_info`, `student_info` (father/mother/guardian name, `contact_number`), `class_teacher_info`, `session_info`, `class_info`, `section_info`, `shift_info`, `group_info`, `users`, `institute_info`. Written: `attendance_info.dN` (only cells `0`/empty/`2` become `3`, and `attendance_modifier`).

## 5. Rules built in

- Server computes total days (calendar or working days by setting); overlap with a pending/approved application is refused.
- Decisions use `UPDATE … WHERE status = <expected>` so two reviewers can't both decide.
- Reject needs remarks at every step. Approved/rejected can only change through admin "Correct" (logged, attendance undone/redone).
- Early leave: leave date today … today + `el_max_advance_days`, not a weekly off day, time before closing time and (today) at least `el_min_lead_minutes` ahead; one open request per student per day; guardian and pickup are chosen from records, never typed.
- A guardian's own request counts as parent-approved (FR-12.6).
- Pending requests past their leave time expire automatically whenever an early leave page or action runs (no cron).
- Gate: release only today's `approved` request; check per `el_pickup_verify`; unlisted person only if `el_allow_unregistered` with a reason.

## 6. Not done yet

- **Notification sending.** Messages are queued in `sl_notifications`; a sender (SMS via `Sms_lib`/push) still has to read `status = queued`.
- ~~Mobile app API~~ Done 2026-10-23: see [leave_api_v1.md](leave_api_v1.md) (no-token mode for now: `database` + `user_type` + `user_id`; guardian = mobile number).
- Attachment upload uses the same Google Cloud bucket as homework; it was not exercised in local tests.
- Half-day leave (column `total_days` is decimal so it can be added later).
- Waiting attendance days are applied when the review queue is opened after the month sheet is created.
