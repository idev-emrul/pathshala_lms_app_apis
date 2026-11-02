# Quiz Acceptance & Assessment — মডিউল প্ল্যান (Module 13)

> এই ফাইলটা রেফারেন্স ডকুমেন্ট। বারবার পুরো requirement বা কোড না দেখে এখান থেকেই কাজ শুরু করা যাবে।
> স্ট্যাটাস: **Phase 1 + 2 বাস্তবায়িত (2026-10-09)** — বিস্তারিত §১২ এ। Phase 3–4 বাকি। (আপডেট: 2026-10-07 — সব টেবিল একটাই ডাটাবেসে)

---

## ১. সংক্ষেপে মডিউলটা কী

শিক্ষক কুইজ তৈরি করে নির্দিষ্ট ক্লাস/সেকশনে publish করবেন → ছাত্র কুইজ পাবে (Pending) → deadline এর আগে **Accept** করবে → কুইজ দেবে (অ্যাপে অথবা ক্লাসে/অফলাইনে) → শিক্ষক মূল্যায়ন করে marks, remarks, feedback দেবেন → **Release** করলে তবেই ছাত্র/অভিভাবক রেজাল্ট দেখবে → রিপোর্ট, summary card আর chart।

```
Teacher creates → Publish → Student receives (Pending)
      → Accept (deadline এর আগে)  ──✗ deadline পার → Not Accepted (auto)
      → Quiz attempt (in-app / offline)
      → Teacher evaluates (auto-score + confirm)
      → Release → Student/Guardian দেখে Marks + Remarks + Feedback
```

সাথে একটা **Question Bank** (eproshnobank এর মতো): বোর্ড প্রশ্ন, নামকরা স্কুলের প্রশ্ন, অধ্যায়ভিত্তিক MCQ/CQ — শিক্ষক এখান থেকে প্রশ্ন বেছে কুইজ বানাবেন।

---

## ২. eproshnobank থেকে যে আইডিয়া নেব

eproshnobank মূলত "১ ক্লিকে প্রশ্ন তৈরীর সফটওয়্যার"। বাংলাদেশি প্রশ্নব্যাংকের স্ট্যান্ডার্ড স্ট্রাকচার:

| স্তর | উদাহরণ |
|---|---|
| ক্লাস | ৬ষ্ঠ – ১০ম, SSC, HSC |
| বিষয় | গণিত, সাধারণ বিজ্ঞান, বাংলা ২য় পত্র... |
| অধ্যায় / টপিক | অধ্যায় ১: বাস্তব সংখ্যা |
| প্রশ্নের উৎস | বোর্ড (ঢাকা/রাজশাহী/... + সাল), নামকরা স্কুল (+ সাল + পরীক্ষার নাম), টেক্সটবুক, নিজস্ব |
| প্রশ্নের ধরন | MCQ (সাধারণ / বহুপদী / অভিন্ন তথ্যভিত্তিক), সৃজনশীল (CQ: উদ্দীপক + ক/খ/গ/ঘ), সংক্ষিপ্ত প্রশ্ন, শূন্যস্থান, সত্য/মিথ্যা |
| ফিল্টার | ক্লাস → বিষয় → অধ্যায় → উৎস/সাল → ধরন → কঠিনতা |

শিক্ষকের ফ্লো: ফিল্টার করো → চেকবক্সে প্রশ্ন সিলেক্ট করো → মার্ক দাও → কুইজে যোগ করো (অথবা "Random 10 MCQ from Chapter 1-3")।

> ⚠️ **কপিরাইট নোট:** eproshnobank এর ডাটা scrape/কপি করা যাবে না। আমাদের ব্যাংক ভরাট হবে (ক) নিজস্ব ডাটা এন্ট্রি, (খ) Excel import (PhpSpreadsheet আছে), (গ) শিক্ষকদের নিজস্ব প্রশ্ন দিয়ে। শুধু **স্ট্রাকচার/UX আইডিয়া** নেব।

---

## ৩. আর্কিটেকচার সিদ্ধান্ত

### ৩.১ একটাই ডাটাবেস

**সব নতুন টেবিল একটাই ডাটাবেসে** — যেখানে আগে থেকে `class_info`, `subject_info`, `section_info`, `student_current_status_info` আছে, অর্থাৎ যেটা `$this->db`। আলাদা central/question-bank DB নেই।

এর ফলে:
- প্রশ্নব্যাংক সরাসরি বিদ্যমান `class_code` আর `subject_code` ব্যবহার করবে — আলাদা গ্লোবাল ক্লাস/বিষয় টেবিল বা mapping টেবিল লাগবে না।
- স্কুলের নিজস্ব প্রশ্ন আর বোর্ড/স্কুল প্রশ্ন **একই** `qb_questions` টেবিলে; `visibility` কলাম দিয়ে আলাদা (`public` = সবাই দেখবে, `private` = শুধু যে শিক্ষক বানিয়েছেন)।
- রিপোর্টে সাধারণ JOIN চলবে, cross-database query লাগবে না।
- `database.php` বা অন্য কোনো existing কনফিগ বদলাতে হবে না।

### ৩.২ Question Snapshot

কুইজে প্রশ্ন যোগ করার সময় প্রশ্নের টেক্সট, অপশন আর সঠিক উত্তর **কুইজের নিজের টেবিলে কপি (snapshot)** করে রাখব (`qz_quiz_questions.snapshot_json`)।
কেন: ব্যাংকে পরে প্রশ্ন এডিট/ডিলিট হলে আগের কুইজের প্রশ্নপত্র আর রেজাল্ট বদলে যাবে না। Grading হবে snapshot থেকে।

### ৩.৩ নিরাপত্তা নিয়ম (Master §11)

- **Answer key কখনো client এ যাবে না** submission এর আগে। Attempt API শুধু প্রশ্ন + অপশন পাঠাবে, `is_correct` বাদ দিয়ে।
- স্কোরিং **শুধু সার্ভারে**।
- **সার্ভার টাইম authoritative**: accept/submit এ `date('Y-m-d H:i:s')` (Asia/Dhaka), client এর সময় বিশ্বাস করব না।
- ছাত্র শুধু নিজের ডাটা দেখবে: `student_id` নেব টোকেনের user থেকে, POST/URL থেকে না।
- একাধিক submission ব্লক: `attempt_no < max_attempts` চেক + DB তে unique key।
- Timer: `expires_at` সার্ভারে সেভ; সময় পার হলে `auto_submitted`।

### ৩.৪ "Not Accepted" কীভাবে অটোমেটিক হবে

1. **Lazy update (মূল ভরসা):** লিস্ট/রিপোর্ট/accept লোডের আগে —
   `UPDATE qz_quiz_students SET status='not_accepted' WHERE status='pending' AND quiz.accept_deadline < NOW()`
   cron না চললেও ডাটা সঠিক থাকবে।
2. **Cron (ঐচ্ছিক):** শুধু নোটিফিকেশনের জন্য ("Deadline Reminder", "Not Accepted")। নতুন controller `qz_cron`।

### ৩.৫ দুটো deadline

- `accept_deadline`: এর মধ্যে Accept করতে হবে
- `due_at`: এর মধ্যে কুইজ জমা দিতে হবে (in-app হলে)
- নিয়ম: `accept_deadline <= due_at`; ফর্মে ডিফল্ট দুটো একই।

---

## ৪. টেবিল ডিজাইন (সব একটাই DB তে)

Prefix: `qb_` = প্রশ্নব্যাংক, `qz_` = কুইজ, `perf_` = Module 9 এর সাথে শেয়ার।
ছাত্র রেফারেন্স: `student_current_status_info.current_status_info_id` (hw মডিউলের মতো)।
বিদ্যমান টেবিল (শুধু পড়া হবে, বদলানো হবে না): `session_info`, `class_info`, `section_info`, `shift_info`, `group_info`, `subject_info`, `teacher_info`, `student_current_status_info`, `student_info`, `users`।

### ৪.১ Question Bank (`qb_`)

| টেবিল | কাজ | কেন দরকার |
|---|---|---|
| `qb_chapters` | অধ্যায়/টপিক: class_code, subject_code, chapter_no, name, parent_id | অধ্যায়ভিত্তিক ফিল্টার; parent_id দিয়ে sub-topic |
| `qb_sources` | উৎস: source_type (`board`/`school`/`textbook`/`model_test`/`custom`), name, year, exam_name | "বোর্ড প্রশ্ন ২০২৩", "নামকরা স্কুল" ফিল্টার |
| `qb_questions` | মূল প্রশ্ন: class_code, subject_code, chapter_id, question_type, stem, stimulus, difficulty, default_marks, explanation, visibility, status, created_by, approved_by | প্রশ্নব্যাংকের মূল টেবিল; বোর্ড প্রশ্ন + শিক্ষকের নিজস্ব প্রশ্ন একসাথে |
| `qb_question_sources` | question_id ↔ source_id | একই প্রশ্ন একাধিক বোর্ড/সালে আসতে পারে ("ঢা.বো. ২২, রা.বো. ২৩") |
| `qb_question_options` | MCQ অপশন: question_id, label (ক/খ/গ/ঘ), text, is_correct, sort_order | MCQ ও বহুপদী সমাপ্তিসূচক |
| `qb_question_parts` | CQ অংশ: question_id, label, part_type (জ্ঞান/অনুধাবন/প্রয়োগ/উচ্চতর দক্ষতা), text, marks, model_answer | সৃজনশীল প্রশ্নের স্ট্রাকচার |
| `qb_question_media` | ছবি/ডায়াগ্রাম (cloud path) | বিজ্ঞান/গণিতের চিত্র |

`question_type`: `mcq_single`, `mcq_multi`, `mcq_common`, `true_false`, `fill_blank`, `short`, `cq`।

> পুরনো `vir_question_setup` (vexam) শুধু ৪-অপশন MCQ রাখে। পুরনো টেবিল ছোঁয়া হবে না; পরে চাইলে import script দিয়ে `qb_questions` এ আনা যাবে।

### ৪.২ Setup

| টেবিল | কাজ | কেন দরকার |
|---|---|---|
| `qz_settings` | policy: default_max_attempts, not_accepted_policy (`zero`/`exclude`), allow_reopen, reminder_hours_before, auto_release | FR + Open Items school-configurable |
| `perf_grading_bands` | remarks band: min_percent, max_percent, label_en, label_bn, color, scope | FR-13.10; Module 9 এর সাথে **একই টেবিল** |

### ৪.৩ Quiz core

**`qz_quizzes`** — কুইজের মূল রেকর্ড (FR-13.1)

| কলাম | নোট |
|---|---|
| id, title, instructions | |
| academic_session_id, class_id, shift_id, section_id, group_id, subject_id, teacher_id | টার্গেটিং; hw_assignments এর মতো |
| conduct_mode | `in_app` / `offline` |
| total_marks | in_app হলে প্রশ্নের marks এর যোগফল; offline হলে শিক্ষক দেবেন |
| duration_minutes | in_app timer; NULL = timer নেই |
| publish_at, accept_deadline, start_at, due_at | |
| max_attempts, shuffle_questions, shuffle_options | |
| result_release_mode | `manual` / `auto_after_due` |
| status | `draft` → `published` → `closed` → `evaluated` → `released`; `cancelled` |
| source_module, source_ref_id | `quiz` / `live_class` / `activity_channel` (FR-13.12) |
| notify_on_publish, is_deleted, created_by, created_at, updated_by, updated_at, published_at, released_at | |

**`qz_quiz_questions`** — কুইজে কোন প্রশ্ন

| কলাম | নোট |
|---|---|
| id, quiz_id, sort_order | |
| question_id | `qb_questions.id`; NULL হলে শুধু এই কুইজের জন্য লেখা inline প্রশ্ন |
| question_type, marks, negative_marks | |
| snapshot_json | প্রশ্ন + অপশন + **সঠিক উত্তর**; কখনো ছাত্রের API তে যাবে না |

**`qz_quiz_students`** — প্রতি ছাত্রের acceptance + ফাইনাল রেজাল্ট

| কলাম | নোট |
|---|---|
| id, quiz_id, student_id | UNIQUE(quiz_id, student_id) |
| status | `pending` / `accepted` / `not_accepted` / `reopened` / `exempted` |
| received_at, accepted_at, accept_ip | FR-13.3 |
| reopened_by, reopened_at, reopen_deadline | শিক্ষক reopen করলে |
| obtained_marks, percentage, grading_band_id, remarks_label, teacher_feedback | |
| evaluation_status, evaluated_by, evaluated_at | |
| is_released, released_at | FR-13.7 |
| reminder_sent_at | duplicate reminder ঠেকাতে |

**`qz_attempts`** — প্রতিটা চেষ্টা (in-app)

| কলাম | নোট |
|---|---|
| id, quiz_student_id, attempt_no | UNIQUE(quiz_student_id, attempt_no) |
| started_at, expires_at, submitted_at | |
| status | `in_progress` / `submitted` / `auto_submitted` / `evaluated` |
| question_order_json | shuffle অর্ডার, refresh এ বদলাবে না |
| auto_score, manual_score, final_score, client_ip, user_agent | |

**`qz_attempt_answers`**

| কলাম | নোট |
|---|---|
| id, attempt_id, quiz_question_id | UNIQUE(attempt_id, quiz_question_id) |
| selected_option_ids, answer_text, answer_file_path | |
| is_correct, auto_marks, teacher_marks, teacher_comment, saved_at | |

**`qz_notification_events`** — event_type (`quiz_received` / `deadline_reminder` / `not_accepted` / `result_released`), quiz_id, student_id, payload, occurred_at, processed_at

**`qz_activity_log`** (ঐচ্ছিক) — কে কখন publish/evaluate/release/reopen করল।

### ৪.৪ ER সারসংক্ষেপ

```
class_info / subject_info ─< qb_chapters ─< qb_questions ─< qb_question_options
                                              │        ├─< qb_question_parts
                                              │        ├─< qb_question_media
                                              └─< qb_question_sources >─ qb_sources

qz_quizzes ─< qz_quiz_questions (snapshot, question_id → qb_questions)
    │
    └─< qz_quiz_students (student_id → student_current_status_info)
            └─< qz_attempts ─< qz_attempt_answers
perf_grading_bands, qz_settings, qz_notification_events, qz_activity_log
```

মোট নতুন টেবিল: **১৬টি** (qb ৭ + setup ২ + quiz ৭)।

---

## ৫. ফাইল/মডিউল স্ট্রাকচার (সব নতুন ফাইল)

| Controller | Model | কাজ |
|---|---|---|
| `qz_question_bank.php` | `qz_question_bank_model.php` | প্রশ্নব্যাংকে প্রশ্ন যোগ, Excel import, approve |
| `qz_quiz.php` | `qz_quiz_model.php` | শিক্ষক: কুইজ তৈরি, প্রশ্ন বাছাই, publish, evaluate, release, reopen |
| `qz_student.php` | `qz_student_model.php` | ছাত্র: লিস্ট, accept, attempt, result (JSON API) |
| `qz_report.php` | `qz_report_model.php` | ছাত্র রিপোর্ট, পারফরম্যান্স সামারি, শিক্ষক analysis, অভিভাবক ভিউ |
| `qz_setup.php` | `qz_setup_model.php` | Admin: grading bands, settings |
| `qz_cron.php` | (qz_quiz_model) | reminder / not-accepted নোটিফিকেশন |

Views: `application/views/admin/qz_quiz/`, `qz_report/`, `qz_setup/`, `qz_question_bank/` — CSS/JS ভিউ ফাইলের ভেতরেই।

---

## ৬. স্ক্রিন ও এন্ডপয়েন্ট ম্যাপ

| স্ক্রিন | Controller::method | নোট |
|---|---|---|
| Teacher — Quiz List | `qz_quiz/quizList` | ফিল্টার: session, month, class, section, subject, status |
| Teacher — Create/Edit Quiz | `qz_quiz/quizForm`, `quizSave` | ধাপ ১: তথ্য; ধাপ ২: প্রশ্ন |
| Teacher — Question Picker | `qz_quiz/questionPicker`, `questionSearch`, `quizQuestionAdd`, `autoPick` | eproshnobank স্টাইল ফিল্টার + "random N" |
| Teacher — Publish | `qz_quiz/quizPublish` | টার্গেট ছাত্রদের জন্য pending রো + নোটিফিকেশন |
| Teacher — Evaluate | `qz_quiz/evaluate/{quiz_id}`, `evaluateSave` | auto-score দেখাবে, শিক্ষক confirm |
| Teacher — Release / Reopen | `qz_quiz/release`, `reopen` | |
| Student — Report (Fig 13.1) | `qz_student/report` | কার্ড + লিস্ট + পারফরম্যান্স সামারি + চার্ট |
| Student — Details & Accept | `qz_student/detail`, `accept` | সার্ভারে deadline চেক |
| Student — Attempt | `qz_student/start`, `saveAnswer`, `submit` | answer key ছাড়া প্রশ্ন |
| Student — Result & Feedback | `qz_student/result/{id}` | শুধু `is_released=1` হলে |
| Guardian — Child's Quiz | `qz_report/guardianChild` | সন্তান কিনা সার্ভারে যাচাই |
| Teacher — Quiz-wise Analysis | `qz_report/quizAnalysis` | acceptance rate, avg, highest, lowest |
| Admin — Class/Subject Summary | `qz_report/classSubjectSummary` | |
| Admin — Bands/Settings | `qz_setup/...` | |

---

## ৭. হিসাবের সূত্র (প্রস্তাবিত — কনফার্ম করতে হবে)

| ফিল্ড | সূত্র |
|---|---|
| Total Quizzes | ফিল্টারে ছাত্রের সব `qz_quiz_students` রো (cancelled বাদ) |
| Accepted / Pending / Not Accepted | status অনুযায়ী count (lazy update এর পর) |
| Total Marks (কার্ড) | **released কুইজগুলোর** total_marks এর যোগফল |
| Total Marks Obtained | released কুইজের obtained_marks যোগফল, "85 / 100" আকারে |
| Percentage (প্রতি কুইজ) | obtained / total × 100, ১ দশমিক পর্যন্ত |
| Average Percentage | released কুইজগুলোর percentage এর **গড়** |
| Best / Lowest | released কুইজের মধ্যে সর্বোচ্চ/সর্বনিম্ন percentage + কুইজের নাম |
| Acceptance rate (শিক্ষক) | accepted / total targeted × 100 |
| Not Accepted স্কোরে | `qz_settings.not_accepted_policy` = `exclude` (ডিফল্ট) অথবা `zero` |

Remarks band (প্রস্তাবিত ডিফল্ট):

| % | Label | বাংলা |
|---|---|---|
| 90–100 | Excellent | চমৎকার |
| 80–89.99 | Very Good | খুব ভালো |
| 70–79.99 | Good | ভালো |
| 60–69.99 | Satisfactory | সন্তোষজনক |
| 40–59.99 | Needs Improvement | উন্নতি প্রয়োজন |
| 0–39.99 | Poor | দুর্বল |

---

## ৮. মূল লজিক (pseudo)

**Publish**
1. status `draft` কিনা, অন্তত ১টা প্রশ্ন আছে কিনা (in_app হলে) যাচাই
2. `student_current_status_info` থেকে টার্গেট ছাত্র (session+class+shift+section+group, active_status=1)
3. যাদের রো নেই তাদের জন্য `qz_quiz_students` status=`pending`
4. quiz status=`published`, published_at; `qz_notification_events` এ `quiz_received`
5. সব transaction এর ভেতরে

**Accept**
1. টোকেনের user → current_status_info_id; রো নিজের ও `pending`/`reopened` কিনা
2. `NOW() <= accept_deadline` (reopened হলে reopen_deadline)
3. `UPDATE ... SET status='accepted', accepted_at=NOW() WHERE id=? AND status IN('pending','reopened')` — affected_rows দেখে race condition ঠেকানো

**Start attempt (in_app)**
1. status=`accepted`, NOW() start_at আর due_at এর মধ্যে, attempt count < max_attempts
2. চলমান `in_progress` থাকলে সেটাই ফেরত
3. নতুন attempt: expires_at = MIN(NOW()+duration, due_at)
4. প্রশ্ন ফেরত — snapshot থেকে **correct answer সরিয়ে**

**Submit**
1. attempt নিজের ও `in_progress`; সময় পার হলে `auto_submitted`
2. MCQ সার্ভারে auto-score; শুধু objective হলে evaluation_status=`auto_scored`

**Evaluate → Release**
1. marks/feedback → percentage → `perf_grading_bands` থেকে band
2. Release: `is_released=1`, `released_at`, নোটিফিকেশন `result_released`

---

## ৯. Open Items — সিদ্ধান্ত লাগবে

| # | প্রশ্ন | প্রস্তাব |
|---|---|---|
| ১ | Summary এর সূত্র | §৭ এর সূত্র |
| ২ | Remarks band ও ৭০% এর নিচের label | §৭ এর টেবিল; Module 9 এর সাথে শেয়ার |
| ৩ | কুইজ কীভাবে হবে | দুটোই (`conduct_mode`); Phase 1: offline, Phase 2: MCQ in-app |
| ৪ | Not Accepted স্কোরে ধরা হবে কিনা, reopen | ডিফল্ট exclude; শিক্ষক reopen করতে পারবেন |
| ৫ | কুইজ মার্ক CA / Module 9 এ যাবে কিনা | এখন না; পরে `qz_quiz_students` থেকে পড়া যাবে |
| ৬ | প্রশ্নব্যাংক কে ভরাট করবে | ডাটা এন্ট্রি + Excel import + শিক্ষকের নিজস্ব প্রশ্ন |
| ৭ | Guardian–student সম্পর্ক কোন টেবিলে | কোডিং এর আগে বিদ্যমান টেবিল খুঁজতে হবে |
| ৮ | Student login ↔ `current_status_info_id` | `authentication` / `users` দেখে কনফার্ম |
| ৯ | Month ফিল্টার কোন তারিখে | `due_at` এর মাস দিয়ে |

---

## ১০. ধাপে ধাপে বাস্তবায়ন (Phases)

**Phase 1 — Core (offline quiz)**
- টেবিল: `qz_settings`, `perf_grading_bands`, `qz_quizzes`, `qz_quiz_students`, `qz_notification_events`
- শিক্ষক: create → publish → evaluate → release; ছাত্র: list, accept, result; রিপোর্ট Fig 13.1

**Phase 2 — In-app MCQ**
- টেবিল: `qb_questions`, `qb_question_options`, `qz_quiz_questions`, `qz_attempts`, `qz_attempt_answers`
- timer, auto-score, শিক্ষক confirm, max_attempts

**Phase 3 — Question Bank (eproshnobank স্টাইল)**
- টেবিল: `qb_chapters`, `qb_sources`, `qb_question_sources`, `qb_question_parts`, `qb_question_media`
- Data entry UI + Excel import + approve; Question Picker; প্রিন্টযোগ্য প্রশ্নপত্র (mPDF)

**Phase 4 — বাকি**
- CQ/short answer, Guardian ভিউ, analysis, cron + SMS/push, Live Class ও Activity Channel integration, `qz_activity_log`

---

## ১১. কোড লেখার আগে চেকলিস্ট

- [ ] §৯ এর Open Items কনফার্ম
- [ ] Guardian ↔ student আর student login ↔ `current_status_info_id` ম্যাপিং বের করা
- [ ] Module 9 এ আগে থেকে grading band টেবিল আছে কিনা দেখা
- [ ] Chart লাইব্রেরি assets এ আছে কিনা
- [ ] প্রতিটা নতুন controller/model এ CLAUDE.md এর boilerplate হুবহু
- [ ] PHP 5.6 compatible
- [ ] সব SQL আলাদা `.sql` ফাইলে (যেমন `quiz_module_schema.sql`)

---

## ১২. বাস্তবায়ন — Phase 1 + 2 (2026-10-09)

§৯ এর সব প্রস্তাবই ডিফল্ট হিসেবে নেওয়া হয়েছে। ছাত্রের দিক এখন শুধু staff "View as Student" ওয়েব পেজ (hw মডিউলের মতো); অ্যাপ API পরে।

### ১২.১ ফাইল (সব নতুন)

| ফাইল | কাজ |
|---|---|
| `application/sql/quiz_module_schema.sql` | Phase 1+2 এর ১০টি টেবিল (`CREATE TABLE IF NOT EXISTS`) |
| `controllers/qz_setup.php` + `models/qz_setup_model.php` | টেবিল সেটআপ, `qz_settings`, `perf_grading_bands` (Admin / Super Admin) |
| `controllers/qz_quiz.php` + `models/qz_quiz_model.php` | শিক্ষক: লিস্ট, তৈরি/এডিট, প্রশ্ন, প্রশ্নব্যাংক পিকার, publish, evaluate, release, reopen, cancel; lazy status update; স্কোরিং |
| `controllers/qz_student.php` + `models/qz_student_model.php` | staff "View as Student": ছাত্র বাছাই, রিপোর্ট (Fig 13.1), accept, attempt |
| `views/admin/qz_setup/setup.php` | সেটআপ পেজ |
| `views/admin/qz_quiz/quizList.php`, `quizForm.php`, `quizQuestions.php`, `evaluate.php` | শিক্ষকের পেজ |
| `views/admin/qz_student/studentList.php`, `report.php`, `quizView.php`, `attempt.php` | ছাত্রের পেজ (staff view) |

বিদ্যমান কোনো ফাইল বদলানো হয়নি। সাইডবার মেনু যোগ করা হয়নি (নিচের URL দিয়ে খুলুন)।

### ১২.২ পেজ (client দিয়ে: `.../information/index/<path>`)

| পেজ | Path |
|---|---|
| Quiz Setup (প্রথমে একবার) | `qz_setup/setup` |
| Quiz List | `qz_quiz/quizList` |
| Create Quiz | `qz_quiz/quizForm` |
| View as Student | `qz_student/studentList` |

### ১২.৩ টেবিল (১০টি)

`qz_settings`, `perf_grading_bands`, `qb_questions`, `qb_question_options`, `qz_quizzes`, `qz_quiz_questions`, `qz_quiz_students`, `qz_attempts`, `qz_attempt_answers`, `qz_notification_events`।
প্ল্যানের বাইরে যোগ: `qz_quiz_students.accepted_by` / `accepted_via` আর `qz_attempts.started_by` / `via` (`app` / `proxy_web`) — staff কারো হয়ে করলে চিহ্ন থাকে।

### ১২.৪ যে সিদ্ধান্তগুলো কোডে নেওয়া হয়েছে

| বিষয় | নিয়ম |
|---|---|
| প্রশ্নের ধরন (Phase 2) | `mcq_single`, `mcq_multi`, `true_false` |
| স্কোরিং | সঠিক = marks, ভুল = −negative_marks, ফাঁকা = 0; multi তে পুরো সেট মিলতে হবে; attempt এর মোট ০ এর নিচে যায় না |
| একাধিক attempt | **সেরা** attempt এর স্কোর গণ্য |
| Answer key | কখনো attempt পেজে যায় না; release এর পরেই ছাত্র সঠিক উত্তর দেখে |
| Timer | `expires_at` = MIN(শুরু + duration, due); পেজ সার্ভারের বাকি সেকেন্ড থেকে গোনে; সময় শেষে auto-submit (expires_at সময়ে) |
| Lazy update | প্রতিটা পেজ লোডে: pending → not_accepted, মেয়াদ শেষ attempt → auto_submitted, auto-release |
| Remarks band | যে band এর `min_percent` স্কোর ছুঁয়েছে তার মধ্যে সর্বোচ্চটা (৭৯.৯৯ আর ৮০ এর মাঝে ফাঁক নেই) |
| মূল্যায়ন | শুধু `accepted` ছাত্র; in-app এ auto-score আগেই বসে, শিক্ষক বদলাতে / feedback দিতে পারেন (`evaluated`) |
| Release | মার্ক হওয়া সব accepted ছাত্র একসাথে; পরে মার্ক হলে আবার Release চাপলে বাকিরা যায় |
| Reopen | শুধু Not Accepted ছাত্র, নতুন deadline (ভবিষ্যতে) — সেটাই accept ও in-app জমার শেষ সময় |
| Publish এর পর | শুধু title, instructions, deadline, release mode বদলানো যায়; প্রশ্ন লক |
| Cancel / Delete | draft = delete; published (কোনো release হয়নি) = cancel |
| প্রশ্নব্যাংক | কুইজে লেখা প্রশ্ন `qb_questions` এ জমা হয় (private / public); পিকারে নিজের + public প্রশ্ন, একই class + subject; "Add random N" |
| আগের কুইজ থেকে প্রশ্ন | প্রশ্ন পেজের ডান প্যানেলে ডিফল্ট ট্যাব "Earlier quizzes": একই class + subject এর আগের in-app কুইজ (নিজের; admin সব), শিরোনাম বা প্রশ্ন দিয়ে সার্চ। কুইজে ক্লিক → প্রশ্ন বাছাই → যোগ। আগের কুইজের কপি (marks + এডিটসহ) আসে; আগে থেকে থাকা প্রশ্ন বাদ। যোগের পর Edit শুধু এই কুইজের কপি বদলায় (`sourceQuizzes`, `sourceQuestions`, `sourceAdd`) |
| Not Accepted policy `zero` | due পার হলে ছাত্রের গড়ে ০% ধরা হয় |

### ১২.৫ লোকাল টেস্ট (2026-10-09, `pathshala_borderguardsylhet`, Super Admin)

পাস: টেবিল সেটআপ (দুবার চালালেও নিরাপদ) · in-app কুইজ ৩ ধরনের প্রশ্ন · ভুল প্রশ্ন বাতিল · ব্যাংক সার্চ + random · publish (৩০ জন) · দুবার publish বাতিল · accept এর আগে start বাতিল · accept দুবার বাতিল · attempt পেজে `is_correct` নেই · shuffle অর্ডার স্থির · ভুল option বাতিল · স্কোর ২/৬ → দ্বিতীয় attempt ৬/৬ → সেরাটা গণ্য · ৩য় attempt বাতিল · timer শেষে auto-submit · deadline পার হলে ২৮ জন Not Accepted · deadline পরে accept বাতিল · reopen → accept → release এর পরেও start · শিক্ষক override + বাংলা feedback · release দুবার বাতিল · offline কুইজ পুরো ফ্লো · অন্য শিক্ষক কুইজ দেখতে পারে না · সব পেজে PHP error নেই।

লোকাল DB তে টেস্ট ডাটা রয়ে গেছে: quiz 1 (in-app, released), quiz 3 (offline, released), quiz 2 (deleted), `qb_questions` ৩টি।

### ১২.৬ এখনো বাকি

- Phase 3: অধ্যায়, উৎস, CQ, Excel import, approve, প্রিন্টযোগ্য প্রশ্নপত্র
- Phase 4: guardian ভিউ, quiz analysis / class-subject summary রিপোর্ট, cron + SMS/push, Live Class / Activity Channel, `qz_activity_log`
- ছাত্রের অ্যাপ API (`api/v1`) — hw API এর মতো, একই model দিয়ে
- সাইডবার মেনু (চাইলে `admin_left_sidebar.php` এ Homework মেনুর মতো Quiz মেনু)
