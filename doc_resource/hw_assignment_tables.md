# Homework / Assignment — টেবিল ডিজাইন

সোর্স: `application/models/hw_assignment_model.php` → `migrationTableSql()`।
সব টেবিল টোকেন দিয়ে বাছাই করা institute DB তে তৈরি হয় (`runSetup` → `runMigration()`)।
MySQL 5.6 safe: InnoDB, utf8, JSON টাইপ নেই।

Prefix: `hw_`। ছাত্র রেফারেন্স: `student_current_status_info.current_status_info_id`।
বিদ্যমান টেবিল (শুধু পড়া হবে, বদলানো হবে না): `session_info`, `class_info`, `section_info`, `shift_info`, `group_info`, `subject_info`, `teacher_info`, `student_current_status_info`, `users`।

## ১. Setup / Lookup

**`hw_settings`** — প্রতিষ্ঠানের পলিসি

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| institute_code | int | |
| default_assessment_mode | varchar(10) | `simple` / `rubric`, default `simple` |
| allow_mode_override | tinyint(1) | default 1 |
| allow_free_resubmission | tinyint(1) | 0 = শুধু শিক্ষক request করলে |
| allow_late_submission | tinyint(1) | default 1 |
| reminder_hours_before_due | int | default 24 |
| created_at, updated_at | datetime | |

**`hw_assignment_types`** — অ্যাসাইনমেন্টের ধরন

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| type_name | varchar(100) | |
| sort_order | int | |
| status | tinyint(1) | 1 active / 0 inactive |
| created_by, created_at | int, datetime | |

**`hw_feedback_tags`** — মূল্যায়নের সময় দ্রুত feedback ট্যাগ

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| tag_name | varchar(100) | |
| color | varchar(20) | |
| sort_order | int | |
| status | tinyint(1) | 1 active / 0 inactive |

## ২. Assignment core

**`hw_assignments`** — অ্যাসাইনমেন্টের মূল রেকর্ড

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| institute_code | int | |
| academic_session_id | int | `session_info.session_code` |
| class_id | int | `class_info.class_code` |
| shift_id | int NULL | `shift_info.shift_code`, NULL = সব |
| section_id | int NULL | `section_info.section_code`, NULL = সব সেকশন |
| group_id | int NULL | `group_info.group_code`, NULL = সব |
| subject_id | int | `subject_info.subject_code` |
| teacher_id | int NULL | `teacher_info.teacher_code` |
| assignment_type_id | int | `hw_assignment_types.id` |
| title | varchar(255) | |
| description | text | |
| chapter_topic | varchar(255) | |
| assigned_date | date | |
| start_time | time | |
| due_at | datetime | |
| total_marks, passing_marks | decimal(7,2) | |
| priority | varchar(10) | `low` / `medium` / `high` |
| assessment_mode | varchar(10) | `simple` / `rubric` |
| allow_resubmission | tinyint(1) | |
| max_versions | int NULL | |
| notify_on_publish | tinyint(1) | default 1 |
| status | varchar(20) | `draft` → `published` → `closed` |
| published_at, closed_at | datetime | |
| is_deleted, deleted_by, deleted_at | soft delete | |
| created_by, created_at, updated_by, updated_at | | created_by = `users.id` |

Index: (academic_session_id, class_id, section_id), subject_id, teacher_id, (status, is_deleted), created_by

**`hw_assignment_attachments`** — শিক্ষকের দেয়া ফাইল/রেফারেন্স লিংক

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_id | int | `hw_assignments.id` |
| type | varchar(15) | `attachment` / `reference` |
| file_name, file_path | varchar | |
| link | varchar(1000) | |
| file_size | int | bytes |
| created_by, created_at | | |

**`hw_assignment_formats`** — ছাত্র কোন ফরম্যাটে জমা দিতে পারবে

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_id | int | UNIQUE(assignment_id, format) |
| format | varchar(20) | `text` / `pdf` / `image` / `word` / `excel` / `powerpoint` / `audio` / `video` / `link` |
| max_file_size_mb | int | |

**`hw_assignment_rubric_criteria`** — rubric মোডের মানদণ্ড

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_id | int | |
| criteria_name | varchar(150) | |
| max_marks | decimal(7,2) | |
| sort_order | int | |

## ৩. Student / Submission

**`hw_assignment_students`** — প্রতি ছাত্রের স্ট্যাটাস + ফাইনাল মার্ক

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_id, student_id | int | UNIQUE(assignment_id, student_id) |
| status | varchar(30) | `pending` / `submitted` / `under_review` / `resubmission_requested` / `checked` / `missing` |
| is_late | tinyint(1) | |
| first_submitted_at | datetime | |
| latest_submission_id | int | `hw_submissions.id` |
| version_count | int | |
| obtained_marks | decimal(7,2) | |
| score_percent | decimal(5,2) | |
| checked_at, reminder_sent_at, created_at | datetime | |

**`hw_submissions`** — প্রতিটা জমা (version)

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_student_id, assignment_id, student_id | int | |
| version_no | int | default 1 |
| text_answer | text | |
| submitted_at | datetime | |
| is_late | tinyint(1) | |
| resubmission_request_id | int NULL | |
| is_latest | tinyint(1) | default 1 |
| status | varchar(20) | `submitted` / `under_review` / `checked` / `returned` |
| created_at | datetime | |

**`hw_submission_files`** — জমার সাথে ফাইল

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| submission_id | int | |
| file_type | varchar(15) | `photo` / `pdf` / `document` / `link` / `audio` / `video` |
| file_name, file_path | varchar | |
| link | varchar(1000) | |
| file_size | int | |

**`hw_resubmission_requests`** — শিক্ষকের পুনরায় জমার অনুরোধ

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assignment_student_id, submission_id | int | |
| reason | text | |
| new_due_at | datetime | |
| requested_by, requested_at | int, datetime | |
| status | varchar(15) | `open` / `fulfilled` / `cancelled` |

## ৪. Assessment

**`hw_assessments`** — মূল্যায়ন

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| submission_id, assignment_student_id | int | |
| mode | varchar(10) | `simple` / `rubric` |
| obtained_marks, total_marks | decimal(7,2) | |
| score_percent | decimal(5,2) | |
| is_passed | tinyint(1) NULL | |
| feedback_text | text | |
| assessed_by, assessed_at | int, datetime | |

**`hw_assessment_rubric_scores`** — প্রতি মানদণ্ডে মার্ক

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assessment_id | int | |
| criteria_id | int | `hw_assignment_rubric_criteria.id` |
| obtained_marks | decimal(7,2) | |

**`hw_assessment_feedback_tags`** — মূল্যায়ন ↔ ট্যাগ

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| assessment_id, feedback_tag_id | int | UNIQUE(assessment_id, feedback_tag_id) |

## ৫. Notification

**`hw_notification_events`**

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| event_type | varchar(30) | `assigned` / `reminder` / `submitted` / `late` / `missing` / `assessed` / `resubmission_requested` |
| assignment_id | int | |
| assignment_student_id | int NULL | NULL = পুরো ক্লাসের event |
| payload | text | JSON |
| occurred_at, processed_at | datetime | |

**`hw_notification_logs`**

| কলাম | টাইপ | নোট |
|---|---|---|
| id | int PK AI | |
| event_id, user_id | int | |
| channel | varchar(10) | `in_app` / `push` / `sms` / `email` |
| sent_at, read_at | datetime | |

## ৬. ER সারসংক্ষেপ

```
hw_assignment_types ─< hw_assignments ─< hw_assignment_attachments
                            │        ├─< hw_assignment_formats
                            │        ├─< hw_assignment_rubric_criteria
                            │        └─< hw_notification_events ─< hw_notification_logs
                            │
                            └─< hw_assignment_students (student_id → student_current_status_info)
                                    ├─< hw_submissions ─< hw_submission_files
                                    ├─< hw_resubmission_requests
                                    └─< hw_assessments ─< hw_assessment_rubric_scores
                                                     └─< hw_assessment_feedback_tags >─ hw_feedback_tags
hw_settings
```

মোট টেবিল: **১৬টি** (setup ৩ + assignment ৪ + student/submission ৪ + assessment ৩ + notification ২)।
