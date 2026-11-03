# Results API v1 (Module 2)

These are the exam result endpoints for the mobile app. They follow *PATHSHALA Apps — API Contract v1, Module 2* (`PATHSHALA_API_v1.pdf`), and they are built the same way as the homework API. They use this project's envelope, `{ status, message, data }`.

Every figure comes from the web system's stored results and nothing is recalculated. The only exceptions are percentages and the section figures, which the web system does not store. The only write is the class teacher's comment (2.4).

| File | What it holds |
|---|---|
| `app/Http/Controllers/ResultReport/StudentResultController.php` | 2.1 a student's results |
| `app/Http/Controllers/ResultReport/TeacherResultController.php` | 2.2 exam list, 2.3 result sheet, 2.4 comment |
| `app/Services/ResultReport/ResultReader.php` | Queries and JSON shapes, the published rule, section figures |
| `app/Services/ResultReport/CommentService.php` | Saves 2.4 through `tenant_result` |
| `app/Services/ResultReport/ResultCaller.php` | Who is calling, built by the middleware |
| `app/Http/Middleware/AuthorizeResult.php` | `result` (both roles), `result:teacher` |
| `tests/Feature/ResultApiTest.php` | Feature tests |
| `postman/Pathshala_Result_API.postman_collection.json` | Login, then all four endpoints |

## 1. Base URL and caller

| | |
|---|---|
| Local | `http://127.0.0.1:8000/api/v1` |
| Live | `<api host>/api/v1` |

Every request needs `Authorization: Bearer <token>`. The token decides the school and the caller.

| Caller | May use |
|---|---|
| Student (verified) | 2.1, only with their own `current_status_info_id`s |
| Teacher (verified, active login) | 2.1 for a student of a class they teach (class or subject teacher, running session). 2.2–2.4 for the classes they are class teacher of |
| Guest, or a student on a teacher route | 403 |

## 2. Endpoints

| # | Method | Path | Purpose |
|---|---|---|---|
| 2.1 | GET | `/students/{studentId}/results` | Every published exam of the student, with subjects |
| 2.2 | GET | `/teacher/my-class/exams` | Exams of the class teacher's classes |
| 2.3 | GET | `/teacher/my-class/exams/{examId}` | The class result sheet for one exam |
| 2.4 | PUT | `/teacher/my-class/exams/{examId}/students/{studentId}/comment` | Save the teacher's comment |

- `{studentId}` is `current_status_info_id`. In 2.4 it is the `student_id` of a 2.3 row.
- `{examId}` is `exam_class_info.exam_class_id`.

### 2.1 response (`data`)

```json
{
  "rank_visible": true,
  "class_average_visible": true,
  "transcript_url": null,
  "exams": [
    {
      "id": "45",
      "name": "Half Yearly Exam",
      "status": "published",
      "published_at": "2026-06-30T18:00:00Z",
      "total_marks": 800,
      "obtained_marks": 652,
      "percentage": 81.5,
      "gpa": 4.75,
      "grade": "A",
      "position": 3,
      "class_size": 40,
      "class_average": 68.42,
      "marksheet_url": null,
      "subjects": [ { "name": "Bangla", "total_marks": 100, "obtained_marks": 82, "grade": "A+" } ]
    },
    { "id": "47-combined", "name": "Annual Exam (Combined)", "...": "..." }
  ]
}
```

Exams are sorted oldest first, across all of the student's years. `published_at` is UTC: the web's `web_pub_date` at 00:00 Dhaka time.

### 2.2 response (`data`)

```json
[ { "id": "45", "name": "Half Yearly Exam", "status": "published", "class_label": "Six" } ]
```

### 2.3 response (`data`)

```json
{
  "exam": { "id": "45", "name": "Half Yearly Exam", "status": "published", "class_label": "Six" },
  "class_average": 68.42,
  "comments_enabled": true,
  "rows": [
    { "student_id": "1234", "student_name": "Rahim Uddin", "roll": "5", "obtained_marks": 652, "total_marks": 800,
      "gpa": 4.75, "grade": "A", "position": 3, "comment": "Good work" }
  ]
}
```

A student whose result is not made yet has `null` marks, GPA, grade and position.

### 2.4 request

JSON body: `{ "comment": "Good work" }`.

- The `comment` key is required, but its value may be empty or `null`, which clears the comment.
- It can be at most 300 characters after `addslashes`, because `remarks_note` is `varchar(300)`.
- The answer is 200 "Comment saved".

## 3. How the contract maps to the database

| Contract | Here |
|---|---|
| Exam `id` | `exam_class_id`. A combined annual result is an extra exam with the id `{examId}-combined` and the name `... (Combined)` |
| `name` | `exam_class_info.custom_exam_name`, else `exam_info.exam_name` |
| `obtained_marks`, `gpa`, `grade` | `exam_overall_result.overall_total_mark`, `average_gpa`, `letter_grade`. `gpa` is capped at 5, because 5.33 is the web system's "golden A+" marker |
| `total_marks` | `exam_class_info.exam_total_mark` when it is set. Otherwise the sum of the student's counted subjects (`exam_subject_info.no_add_table = 0`) |
| `percentage` | `obtained / total × 100`, to 2 places |
| `position` | `merit_sectionwise` (section position) |
| `class_size`, `class_average` | The students of the **same section** who have a result for the exam, and the average of their percentages |
| `subjects` | `subject_mark_info` (combined: `combine_mark_info`) with `exam_subject_info` and `subject_group`, in subject position order. `obtained_marks` is `exact_total_mark` |
| `comment` (2.3) | `exam_overall_result.remarks_note`, with `stripslashes` |
| `rank_visible`, `class_average_visible` | Always `true` |
| `marksheet_url`, `transcript_url` | Always `null`: the server makes no PDFs |

## 4. Rules

### Stored results

- `exam_overall_result` has one row per student per exam (`final_result = 0`).
- A combined annual exam has another row with `final_result = 1` and `status = 3`.
- Rows with `status = 2` and `final_result = 1` are copies left by older code, and are ignored.
- The table has duplicate rows, so the latest `exam_overall_result_id` wins.

### Published (2.1)

A student sees an exam only when all of these are true:

- `exam_publication_info.published_exam = 1`;
- `web_pub_date` is today or earlier;
- `exam_class_info.examclass_status = 0` (no marks were edited after the merit list);
- `exam_class_active_status = 1`.

Admission tests (`exam_type` 10) and EVS evaluations (12) are left out.

### Teacher

- **Exam status (2.2):**
  - `processing` when marks were edited after the merit list, or when no result has been made yet;
  - `published` by the rule above;
  - otherwise `pending`.

  Teachers see all three.
- **Teacher exams (2.2, 2.3):** exams of the classes in the teacher's `class_teacher_info` rows, in running sessions. A shift of 0 means all. Another class's exam is 403, and an unknown exam is 404.
- **Result sheet (2.3):** every student, not deleted, of the teacher's class / section / group / shift (0 = all), sorted by section and roll. `comments_enabled` is true once any result row exists for the exam.

### Comment (2.4)

The comment is saved the way the web comment screen saves it:

- `remarks_note = addslashes(trim(comment))` and `overall_dom` = today, on **every** `exam_overall_result` row of that student and exam;
- no other column is changed;
- the student must be in the teacher's class (else 403 "This student is not in your class");
- the result row must exist, meaning the merit list was submitted (else 403 "Comments are not enabled for this exam yet");
- it is logged as `activity:comment_result`.

## 5. Write connection

`tenant_result` writes only `exam_overall_result.remarks_note` and `overall_dom`. No new table is needed.

## 6. Errors

Errors use the envelope `{ "status": false, "message": "...", "errors"?: { field: [..] } }`.

| HTTP | Cases |
|---|---|
| 401 | No token, a bad token, or a token that was logged out |
| 403 | Another student's id; a teacher who does not teach the student; another class's exam; a student not in the class (2.4); no result row yet (2.4); the wrong role; a guest; a banned teacher |
| 404 | The student is not found or is deleted; the exam is not found |
| 422 | `comment` is missing or too long (`errors.comment`) |
| 429 | More than 120 requests a minute from one IP (`throttle:result`) |

## 7. Tested on local (2026-11-03)

`ResultApiTest` runs on `pathshala_app_test_school`, with explicit ids, because the test school's `exam_overall_result` has no auto-increment. It covers:

- only published exams, oldest first;
- the stored figures and the section figures;
- the combined annual exam;
- another student's results, and a teacher seeing only their own students;
- the class teacher's exam list with statuses, and the result sheet in roll order;
- another class's exam (403) and an unknown exam (404);
- comments: saved the way the web system saves them; refused outside the class or before the result exists; required and limited to the column size;
- a student on the teacher endpoints.

The full suite passes.

## 8. Open items

- **Confirm with the app team:**
  - section-wise position, size and average (the contract says "class");
  - the `{examId}-combined` id for combined results.
- No marksheet or transcript PDF from the server (`null`).
- On the live databases, check that `exam_overall_result.exam_overall_result_id` is auto-increment. It is not in the test school.
