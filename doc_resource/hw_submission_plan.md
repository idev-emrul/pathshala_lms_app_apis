# Homework Submission & Assessment — Plan

Submit homework as a student from a staff login, let teachers assess it, and show the result back to the student. Later, expose the same logic to the student app as an API.

## 1. Why

Students log in only through the mobile app, and that app's code and login are not available to us. To build and test the student side of the homework module, staff (Super Admin, Admin, Teacher) can **view as a student** on the web: see that student's dashboard and homework, and submit on their behalf. Later, the student app calls an API that runs **the same model code**.

```
View as student (hw_submission/studentView) ──┐
                                              ├──>  Hw_submission_model (rules, submit, history)
Student app API (phase 2)                  ───┘     Hw_assessment_model (marks, feedback, resubmission)
Assess page (hw_assessment/assess)  ──────────────>
```

The pages and the API are only thin wrappers. Once the pages work, the API behaves the same.

## 2. Files (all new)

| File | Purpose |
|---|---|
| `application/controllers/hw_submission.php` | student list, "view as student" page, submit endpoints |
| `application/controllers/hw_assessment.php` | teacher assess page + its AJAX endpoints |
| `application/models/hw_submission_model.php` | submission rules, DB writes, cloud upload, student reads, history (shared with the API) |
| `application/models/hw_assessment_model.php` | save marks / rubric / tags / feedback, request resubmission |
| `application/views/admin/hw_submission/studentList.php` | page 1: session + class → students |
| `application/views/admin/hw_submission/studentView.php` | page 2: the student's dashboard + homework list + submit panel |
| `application/views/admin/hw_assessment/assess.php` | teacher assessment page |

The pages reuse read methods from `hw_assignment_model` (`getAssignment`, `getAttachments`, `getSettings`, `getAssignmentList`, `getRubricCriteria`, lookups). The only existing file changed is the sidebar (section 9).

## 3. Schema change

Two columns are added to `hw_submissions`:

| Column | Type | Note |
|---|---|---|
| `submitted_by` | int NULL | `users.id` of whoever pressed submit (staff now, the student later) |
| `submitted_via` | varchar(15), default `app` | `proxy_web` = staff page, `app` = student app |

`Hw_submission_model::ensureSchema()` adds them when they are missing (`ALTER TABLE … ADD`). It runs when the pages open and before each save, so no manual SQL is needed.

## 4. The pages

Who can open them: Super Admin and Admin (every homework), and Teachers (homework they created or are the teacher of).

### 4.1 View as Student — `hw_submission/studentList`

Pick **session, class** (and optionally shift, section, group) → active students with roll, name, section, and counts: homework, pending, submitted, checked, missing, average score. Each row has **View as student ›**.

### 4.2 Student view — `hw_submission/studentView/<student_id>`

What the student would see (design: StudentDash1 + StudentHw1):
- A staff banner: "Viewing as <name>… saved as their work, marked by staff".
- Header: name, class · section · roll · session, average score, on-time %, completed.
- KPI cards: total, completed, pending, missing, late, resubmit, average score.
- **By subject** (average of checked work) and **Latest feedback** (title, comment, tags, marks).
- **My Homework**: tabs All / Pending / Resubmit / Submitted / Checked / Missing, a subject filter, homework cards. Picking one opens the panel:
  - subject · type · marks, title, due date + time left / late, instructions, teacher files
  - the teacher's resubmission request (reason, new due date)
  - **result** when checked: marks, %, pass/fail, tags, rubric breakdown, feedback
  - the submit form (only the accepted inputs: text, files with photo thumbnails, links), with a confirm step (StudentHw3) showing text/files/links/version/on-time
  - "My submissions": every version with files, late flag, "by staff", marks

### 4.3 Assess Homework — `hw_assessment/assess[/<assignment_id>]`

Design: Assess1 + Assess3.
- Pick session, class, homework (shows "N to check").
- Header pills: to check, checked, resubmit, missing, not yet due.
- **Roster** (left): search, filter chips All / To check / Checked / Missing; status or marks per student.
- **Work** (centre): submitted time, on time / late, version X of Y, version switcher, photo preview with page thumbnails, other files and links, text answer, the "by staff" mark.
- **Assessment** (right): rubric criteria inputs (or one mark in simple mode), live obtained / % / pass, feedback tags (several allowed), feedback text, **Save & next student ›** (jumps to the next student still to check), **Request resubmission** (reason chips + reason, optional new due date and time).

## 5. Endpoints

| Endpoint | Via | Input | Output |
|---|---|---|---|
| `hw_submission/studentList` | `information/index` | session_id, class_id, shift_id, section_id, group_id | page |
| `hw_submission/studentView/<id>` | `information/index` | `student_current_status_info.current_status_info_id` | page |
| `hw_submission/getStudentWork` | `information/index` | `assignment_student_id` | `student`, `assignment`, `state`, `formats[]`, `attachments[]`, `history[]` (with assessment, rubric, tags) |
| `hw_submission/submitWork` | `information/file_upload` | `assignment_student_id`, `text_answer`, `links_json`, files `sub_file_0…9` | `status/title/text`, `submission_id` |
| `hw_assessment/assess[/<id>]` | `information/index` | `session_id` (optional) | page |
| `hw_assessment/getRoster` | `information/index` | `assignment_id` | `assignment`, `criteria[]`, `counts`, `students[]` |
| `hw_assessment/getWork` | `information/index` | `assignment_student_id` | `student`, `history[]`, `current`, `request`, `assess_block`, `request_block` |
| `hw_assessment/saveAssessment` | `information/index` | `assignment_student_id`, `extras` (b64 JSON: marks / rubric / tag_ids), `feedback` (b64) | `status/title/text` |
| `hw_assessment/requestResubmission` | `information/index` | `assignment_student_id`, `reason` (b64), `new_due_date`, `new_due_time` | `status/title/text` |

`submitWork` sends text, links and every file in **one multipart request** through `file_upload`. Free text sent through `information/index` is base64url encoded (`body_encoding=b64url`), because that relay rebuilds the POST body by string concatenation.

## 6. Submission rules (`submissionState()`)

| Rule | Result |
|---|---|
| Assignment deleted / draft / closed | blocked |
| Student has no `hw_assignment_students` row on it | blocked |
| Before `assigned_date + start_time` | blocked ("opens on …") |
| First submission (`pending` / `missing`, 0 versions) | allowed |
| Re-submission | allowed only if there is an **open resubmission request**, or the assignment has `allow_resubmission = 1` and the work is not `checked` yet |
| `max_versions` reached | blocked |
| Past due (the request's `new_due_at` wins if set) | blocked if `hw_settings.allow_late_submission = 0`, otherwise allowed and marked `is_late = 1` |

Input checks in `submitWork()`:
- At least one of text, file or link.
- Text only if `text` is an allowed format, at most 20,000 characters.
- Links only if `link` is allowed, `http(s)` only, at most 10.
- Files: the extension must map to an allowed format, the file must fit that format's `max_file_size_mb` (20 MB when not set), and there can be at most 10.

| Format | Extensions | `hw_submission_files.file_type` |
|---|---|---|
| image | jpg jpeg png gif webp | photo |
| pdf | pdf | pdf |
| word | doc docx | document |
| excel | xls xlsx csv | document |
| powerpoint | ppt pptx | document |
| audio | mp3 m4a wav ogg aac | audio |
| video | mp4 mov webm 3gp mkv | video |
| link | — | link |

## 7. What a successful submit writes

1. All files go to cloud storage first, under `<EIIN>/homework/submission/hws_<assignment>_<student>_<time>_<rand>_<i>.<ext>`.
2. Then one DB transaction:
   - Set `is_latest = 0` on older versions, then insert into `hw_submissions` (`version_no = version_count + 1`, `is_late`, `resubmission_request_id`, `submitted_by`, `submitted_via`).
   - Insert one `hw_submission_files` row per file and per link.
   - Update `hw_assignment_students`: `status = submitted`, `is_late`, `latest_submission_id`, `version_count`, and `first_submitted_at` on the first submit.
   - Set the open `hw_resubmission_requests` row to `fulfilled`.
   - Insert into `hw_notification_events`: `submitted` or `late`.
3. If the transaction fails, the uploaded files are deleted again.

## 7b. Assessment rules (`Hw_assessment_model`)

| Action | Allowed when | Writes |
|---|---|---|
| Save assessment | the student has a latest submission and **no open resubmission request** | `hw_assessments` (one per submission; saving again updates it), `hw_assessment_rubric_scores`, `hw_assessment_feedback_tags`; submission `status = checked`; `hw_assignment_students`: `status = checked`, `obtained_marks`, `score_percent`, `checked_at`; event `assessed` |
| Request resubmission | a latest submission exists, no open request, homework not closed, `max_versions` not used up | `hw_resubmission_requests` (`open`, reason, optional `new_due_at`); latest submission `status = returned`; student `status = resubmission_requested`; event `resubmission_requested` |

Marks checks: simple mode 0…total; rubric mode every criterion 0…its max, total = sum. `is_passed` is set only when the homework has passing marks. Feedback is limited to 2,000 characters and a reason to 1,000.

When the student sends the new version, `submitWork()` sets the request to `fulfilled`, and the teacher can assess again.

## 8. Phase 2 — student app API

A new controller, `hw_student_api.php`, returns JSON only and calls the same model methods:

| Endpoint | Model method |
|---|---|
| `myAssignments` | new list query on `hw_assignment_students` by `student_id` |
| `assignmentDetail` | `getAssignment` + `getFormatRules` + `submissionState` |
| `submit` | `submitWork(…, $via = 'app')` |
| `mySubmissions` | `getSubmissionHistory` |

**Blocked on:** how the student app authenticates. We need from the app team:
- the token format, and how it maps to `student_current_status_info.current_status_info_id`
- how it identifies the institute database

The student id must come from that token, never from a POST field. Otherwise one student could submit for another.

## 9. Sidebar menu

`application/views/admin/include/admin_left_sidebar.php` has a **Homework** menu (Admin, Super Admin, Teacher), placed just before the Seat Plan module. It was added on request; it is the only existing file changed.

| Item | URL |
|---|---|
| Dashboard | `hw_assignment/dashboard` |
| Create Assignment | `hw_assignment/assignmentCreate` |
| Assignment List | `hw_assignment/assignmentList` |
| Assess Homework | `hw_assessment/assess` |
| View as Student | `hw_submission/studentList` |

## 10. Test checklist

**Student side (View as Student)**
- [ ] Session + class shows the right students and counts
- [ ] View as student: KPIs, by subject and latest feedback match the data
- [ ] Tabs and the subject filter narrow the list
- [ ] First submission with text only, a file only, and a link only
- [ ] A file in a format the homework does not accept → rejected
- [ ] A file over the MB limit → rejected
- [ ] Submit after the due time → saved as late (or rejected when late submission is off in `hw_settings`)
- [ ] Second submit with `allow_resubmission = 0` → blocked
- [ ] After the page reloads, the same homework opens again and shows the new version

**Teacher side (Assess Homework)**
- [ ] Rubric mode: a mark over a criterion's max → rejected; the total is the sum
- [ ] Simple mode: one mark out of the total
- [ ] Tags and feedback save; saving again updates the same assessment
- [ ] Save & next opens the next student still to check
- [ ] Request resubmission → the student sees the reason and new due date and can send version 2; v1 stays in history as "returned"
- [ ] `max_versions` used up → Request resubmission is disabled
- [ ] A teacher cannot open another teacher's homework
- [ ] The student view shows marks, rubric breakdown, tags and feedback after checking

## 11. Open items

1. Student app auth details (blocks phase 2).
2. Keep the staff page after go-live (for example, for paper work handed in at school), or hide it?
3. Should the teacher's review screen show proxy submissions differently (`submitted_via = proxy_web`)?
4. Server upload limit: `upload_max_filesize` and `post_max_size` in `php.ini` must be at least as large as one whole submission, because all files go in one request.
