# Behavioural Indicator Report — মডিউল প্ল্যান (Module 7)

> এই ফাইলটা রেফারেন্স ডকুমেন্ট। বারবার পুরো requirement বা কোড না দেখে এখান থেকেই কাজ শুরু করা যাবে।
> স্ট্যাটাস: **বাস্তবায়িত (2026-10-26)**: DB ৭টা টেবিল, Web ৮টা পেজ, API 7.1–7.7 + ২টা প্রস্তাবিত endpoint। বিস্তারিত §১৩ এ; আসল লগইনে পাওয়া সমস্যা আর সমাধান §১৩.৫ এ।
>
> - এই প্ল্যানের পূর্ণ প্রিভিউ: https://claude.ai/artifact/LhKhHC1G1HvpfT8j6tr88S
> - UI ডিজাইন (৭টা স্ক্রিন): https://claude.ai/artifact/Y2DTs4XM1KQiFCHCXhsy8M
> - SQL: `application/sql/behaviour_module_schema.sql` (লোকাল MySQL এ টেস্ট করা, 2026-10-26)
> - সূত্র: CRS v3.1 Module 7 + API Contract v1 (draft) Module 7 (7.1–7.7)

---

## ১. সংক্ষেপে মডিউলটা কী

শিক্ষক ছাত্রের আচরণ দেখে ১০টা indicator এ রেটিং দেবেন, ভালো কাজের জন্য **Positive Recognition** দেবেন, দরকার হলে **Incident** রেকর্ড করবেন → সিস্টেম প্রতি ছাত্রের **Overall Development %** আর indicator-ভিত্তিক % হিসাব করবে → ছাত্র/অভিভাবক প্রোফাইল, recognition, feedback, goals দেখবে → Admin dashboard, incident follow-up আর রিপোর্ট দেখবেন।

মূল নীতি: মডিউলটা **শাস্তিমূলক নয়, উন্নয়নমূলক** (Master §5, §14)। ভালো দিক আগে, incident পরে, শান্ত রঙে। ছাত্র/অভিভাবক incident দেখবে না (API 7.1 server rule)।

```
Quick entry (7.3)  ──→ bh_observations ─< bh_ratings ──→ % হিসাব (7.1, dashboard, report)
Recognition (7.4)  ──→ bh_recognitions                ──→ ছাত্র + অভিভাবক (7.1)
Incident (7.6)     ──→ bh_incidents ─< bh_incident_history
Status (7.7)          reported → under_review → resolved, প্রতিটা বদল history তে
Support টিক        ──→ support_required = 1 ──→ অভিভাবককে push (sl_notifications)
```

---

## ২. Requirement সারসংক্ষেপ (CRS v3.1, Module 7)

| বিষয় | বিস্তারিত |
|---|---|
| Indicators (১০টা) | Responsibility, Respect, Punctuality, Classroom Behaviour, Teamwork, Self-Control, Participation, Leadership, Homework/Assignment Responsibility, School Rules Compliance |
| Rating scale | Excellent (সবুজ) / Good (নীল) / Developing (হলুদ) / Needs Support (কমলা) |
| Recognition tags | Best Behaviour, Most Responsible, Helpful Student, Leadership, Team Player, Punctuality, Positive Improvement |
| Incident ফিল্ড | Date & Time, Location, Category, Description, Reported By, Action Taken, Parent Contact, Follow-up, Status |
| Incident status | Reported → Under Review → Resolved |
| Notification | Positive Recognition, Important Behaviour Update, Support Required, Follow-up Reminder |
| রিপোর্ট | Student-wise, Subject/Class-wise, Monthly, Term, Indicator-wise, Previous vs Current, Admin Dashboard |

| # | Functional requirement |
|---|---|
| FR-7.1 | এক action এ এক ছাত্রের একাধিক indicator এ রেটিং (Quick Entry) |
| FR-7.2 | Overall Development % + indicator-ভিত্তিক % |
| FR-7.3 | Incident status lifecycle + audit trail |
| FR-7.4 | Recognition ছাত্র ও অভিভাবক দেখবে, incident থেকে আলাদা |
| FR-7.5 | Student/Class/Monthly/Term রিপোর্ট + Previous vs Current |
| FR-7.6 | "Support Required" নোটিফিকেশন **শুধু** শিক্ষক/Admin নিজে flag করলে |

| Role | API permission | কী দেখবে/করবে |
|---|---|---|
| Student, Guardian | `behaviour.view` | শুধু নিজের/linked সন্তানের overview (7.1) |
| Teacher | `behaviour.manage` | assigned ছাত্রদের roster, quick entry, recognition, incident দেখা ও লেখা (7.2–7.6) |
| School Admin | `behaviour.resolve` | সব incident দেখা ও status বদল (7.5, 7.7), dashboard |

---

## ৩. আর্কিটেকচার সিদ্ধান্ত

### ৩.১ মাত্র ৭টা টেবিল, কোনো seed নেই

সব `bh_` টেবিল স্কুলের নিজস্ব (tenant) DB তে (`$this->db`)। কোনো existing টেবিল বদলানো হবে না।

### ৩.২ Code সরাসরি রো তে, lookup টেবিল নেই

API তে indicator, rating, tag সব ফিক্সড enum। তাই রো তে সরাসরি API code (`responsibility`, `excellent`, `helpful_student`) রাখা হবে। বাংলা/ইংরেজি নাম, রং আর score থাকবে `bh_common_model` এ PHP array হিসেবে। Incident category API তে **free text**, তাই সেটাও সরাসরি টেক্সট।

### ৩.৩ % সরাসরি হিসাব

রিপোর্টের সময় `bh_ratings` থেকে `AVG(score) GROUP BY indicator`। cache টেবিল নেই। API 7.1 "read (cached)" মানে অ্যাপ নিজে cache করে, সার্ভারে আলাদা টেবিল লাগবে না।

### ৩.৪ Support Required = একটা কলাম

আলাদা টেবিল না। `bh_observations.support_required` আর `bh_incidents.support_required` (API proposal: 7.3 বা 7.6 এ `support_required: true`)। শুধু `1` হলে অভিভাবককে push। "Needs Support" রেটিং দিলে কিছু হবে না (FR-7.6)।

### ৩.৫ যা আগে থেকেই আছে তা ব্যবহার

| দরকার | বিদ্যমান জিনিস |
|---|---|
| Idempotency-Key (7.3, 7.4, 7.6) | `api/v1.php` এর `idempotent()` + `hw_api_idempotency` টেবিল |
| নোটিফিকেশন queue | `sl_notifications` (module = `'bh'`), Student Leave মডিউলের |
| Teacher scope (7.2) | `class_teacher_info` (নিজের section) + `subject_teacher_info` (যে class এ পড়ান) |
| Admin চেক (`behaviour.resolve`) | `api/v1.php` এর `isAdmin()` |
| Student/Guardian চেক | api/v1 এর বিদ্যমান student/guardian যাচাই (হোমওয়ার্ক/কুইজের মতো) |

### ৩.৬ Score ও class snapshot

রেটিং save এর সময় score (100/75/50/25) রো তে কপি, আর observation/recognition/incident এ ঐ দিনের session/class/section। পরে স্কেল বদলালে বা promote হলে পুরনো রিপোর্ট বদলাবে না।

### ৩.৭ Audit trail মুছবে না

`bh_incidents.follow_up_note` এ শুধু সর্বশেষ note (অ্যাপ যেটা দেখায়)। প্রতিটা status বদল আর note `bh_incident_history` তে নতুন রো, কখনো UPDATE/DELETE না (API 7.7 server rule)।

### ৩.৮ ফোল্ডার স্ট্রাকচার

`controllers/behaviour/`, `models/behaviour/`, আর `routes.php` এ route (§৫)। Homework, Quiz, Student Leave এর মতো।

---

## ৪. টেবিল ডিজাইন (৭টা)

| # | টেবিল | কাজ | কলাম |
|---|---|---|---|
| ১ | `bh_observations` | এক শিক্ষকের এক ছাত্রের এক entry | `student_id`, `session_id`, `class_id`, `section_id`, `subject_id`, `teacher_id`, `observed_on`, `comment`, `support_required`, `is_deleted`, `created_by`, `created_at` |
| ২ | `bh_ratings` | entry এর রেটিং, প্রতি indicator এ একটা রো | `observation_id`, `indicator`, `rating`, `score`; UNIQUE (observation_id, indicator) |
| ৩ | `bh_recognitions` | Positive recognition (7.4) | `student_id`, `session_id`, `class_id`, `section_id`, `tag`, `note`, `given_by`, `given_on`, `is_deleted`, `created_by`, `created_at` |
| ৪ | `bh_incidents` | Incident (7.5–7.7) | `student_id`, `session_id`, `class_id`, `section_id`, `occurred_at`, `location`, `category`, `description`, `reported_by`, `action_taken`, `parent_contacted`, `follow_up_note`, `status`, `support_required`, `is_deleted`, `created_by/at`, `updated_by/at` |
| ৫ | `bh_incident_history` | audit trail, **শুধু INSERT** | `incident_id`, `status`, `note`, `created_by`, `created_at` |
| ৬ | `bh_goals` | Improvement goal | `student_id`, `title`, `status` (active/achieved), `created_by`, `created_at`, `updated_at` |
| ৭ | `bh_terms` | টার্মের তারিখ-সীমা (FR-7.5): term রিপোর্ট, trend, previous vs current | `session_id`, `name` (১ম টার্ম / ২য় টার্ম / অর্ধবার্ষিক), `start_date`, `end_date`, `sort_order`, `created_by`, `created_at` |

```
student_current_status_info ─< bh_observations ─< bh_ratings
                            ├─< bh_recognitions
                            ├─< bh_incidents ─< bh_incident_history
                            └─< bh_goals
session_info ─< bh_terms   (observed_on কোন টার্মের তারিখ-সীমায় পড়ে তা দিয়ে মেলানো, কোনো FK নেই)
```

`bh_terms` Admin `bh_setup/setup` পেজে দেবেন। এক session এর টার্মগুলোর তারিখ একটার সাথে আরেকটা overlap করবে না (save এর সময় চেক)।

### ৪.১ Code ও মান (`bh_common_model`)

| Rating | Score | রং | বাংলা |
|---|---|---|---|
| `excellent` | 100 | সবুজ | চমৎকার |
| `good` | 75 | নীল | ভালো |
| `developing` | 50 | হলুদ | উন্নতি হচ্ছে |
| `needs_support` | 25 | কমলা | সহায়তা প্রয়োজন |

Indicator code: `responsibility`, `respect`, `punctuality`, `classroom_behaviour`, `teamwork`, `self_control`, `participation`, `leadership`, `homework_responsibility`, `school_rules_compliance`

Tag code: `best_behaviour`, `most_responsible`, `helpful_student`, `leadership`, `team_player`, `punctuality`, `positive_improvement`

### ৪.২ আগের ডিজাইন থেকে কী বদলালো (৮ → ৭)

যোগ হয়েছে: `bh_terms` (FR-7.5 এর Term রিপোর্ট আর API 7.1 এর টার্ম-ভিত্তিক trend এর জন্য)। বাদ গেছে:

| বাদ | কেন |
|---|---|
| `bh_options` | API এর code সরাসরি রো তে; নাম/রং/score model এ |
| `bh_support_flags` | একটা কলাম `support_required` |
| incident category লিস্ট | API তে free text |
| goals এর next_step, target, progress | API তে শুধু `title`, `status` |

---

## ৫. ফাইল/মডিউল স্ট্রাকচার (সব নতুন ফাইল)

| Controller (`controllers/behaviour/`) | Model (`models/behaviour/`) | কাজ |
|---|---|---|
| `bh_entry.php` | `bh_entry_model.php` | Web: Quick Entry, Class Grid, recognition, feedback, goals |
| `bh_incident.php` | `bh_incident_model.php` | Web: incident লিস্ট, লেখা, status বদল, history |
| `bh_report.php` | `bh_report_model.php` | Web: Admin dashboard, রিপোর্ট (print) |
| `bh_student.php` | (bh_report_model) | Web: ছাত্রের প্রোফাইল, অভিভাবকের Child Development |
| `bh_setup.php` | (bh_common_model) | টেবিল তৈরি (`behaviour_module_schema.sql` চালায়), টার্ম |
| `api/v1.php` (বিদ্যমান) | উপরের ৪টা model | Mobile API 7.1–7.7 (আলাদা api model লাগেনি) |
| — | `bh_common_model.php` | code লিস্ট, নাম, রং, score, টার্ম/period, teacher scope, নোটিফিকেশন |

- Model লোড: `$this->load->model('behaviour/bh_entry_model')`; API তে `useModel('behaviour/bh_common_model')` ইত্যাদি।
- Views: `application/views/admin/bh_entry/`, `bh_incident/`, `bh_report/`, `bh_student/`, `bh_setup/`। CSS/JS ভিউর ভেতরেই।
- `routes.php` এ যোগ:

```php
// Behaviour module (Module 7): controllers live in controllers/behaviour/
$route['bh_entry']            = 'behaviour/bh_entry';
$route['bh_entry/(:any)']     = 'behaviour/bh_entry/$1';
$route['bh_incident']         = 'behaviour/bh_incident';
$route['bh_incident/(:any)']  = 'behaviour/bh_incident/$1';
$route['bh_report']           = 'behaviour/bh_report';
$route['bh_report/(:any)']    = 'behaviour/bh_report/$1';
$route['bh_student']          = 'behaviour/bh_student';
$route['bh_student/(:any)']   = 'behaviour/bh_student/$1';
$route['bh_setup']            = 'behaviour/bh_setup';
$route['bh_setup/(:any)']     = 'behaviour/bh_setup/$1';
```

---

## ৬. API → DB ম্যাপ

| # | Endpoint | কে | টেবিল | মূল কাজ |
|---|---|---|---|---|
| 7.1 | `GET /students/{id}/behaviour` | student, guardian | পড়ে: `bh_ratings` + `bh_observations`, `bh_recognitions`, `bh_goals`, `bh_terms` | overall %, indicators (+ previous_percent), recognitions, trend, feedback, goals; **incident পাঠাবে না** |
| 7.2 | `GET /teacher/behaviour/roster` | teacher | পড়ে: `student_current_status_info` + teacher scope | শুধু assigned ছাত্র |
| 7.3 | `POST /behaviour/quick-entry` | teacher | লেখে: ১টা `bh_observations` + N টা `bh_ratings` | `ratings` object এ ১+ entry; ঐচ্ছিক `comment`, `support_required` |
| 7.4 | `POST /behaviour/recognition` | teacher | লেখে: `bh_recognitions` | `tag` বাধ্যতামূলক; push ছাত্র + অভিভাবক |
| 7.5 | `GET /behaviour/incidents` | teacher, admin | পড়ে: `bh_incidents` (+ `bh_incident_history` → `history[]`) | teacher = assigned ছাত্রদের; admin = পুরো স্কুল |
| 7.6 | `POST /behaviour/incidents` | teacher | লেখে: `bh_incidents` + প্রথম history রো | status সবসময় `reported`; `reported_by` টোকেন থেকে; response `{ id }` |
| 7.7 | `PATCH /behaviour/incidents/{id}` | admin | `bh_incidents` আপডেট + নতুন history রো | teacher হলে 403; শুধু সামনের দিকে status |
| প্রস্তাব | `GET /admin/behaviour/dashboard` | admin | সব টেবিল থেকে count | §৮ এর সূত্র |
| প্রস্তাব | `GET /behaviour/reports?scope=&period=` | admin, teacher | `bh_ratings` + `bh_observations` + `bh_terms` | student/class, month/term |

ID ফরম্যাট (`student-rahim`, `inc-3`) api/v1 এর বিদ্যমান নিয়ম অনুযায়ী (`00_Conventions.md`)। DB তে int id।

---

## ৭. স্ক্রিন ও এন্ডপয়েন্ট ম্যাপ (Web)

| স্ক্রিন | UI বোর্ড | Controller::method |
|---|---|---|
| Admin — Behaviour Dashboard | A1 | `bh_report/dashboard` |
| Admin — Incident Log | A2 | `bh_incident/incidentList`, `incidentSave`, `statusChange` |
| Teacher — Quick Entry | T1 | `bh_entry/quickEntry`, `quickSave`, `recognitionSave` |
| Teacher — Class Grid | T2 | `bh_entry/classGrid`, `gridSave` |
| Goals | S1/G1 | `bh_entry/goalSave`, `goalStatus` |
| Student — My Behaviour Profile | S1 | `bh_student/profile` |
| Guardian — Child Development | G1 | `bh_student/guardianChild` |
| Behaviour Report | R1 | `bh_report/report`, `reportExcel`, `reportPdf` |
| Setup + টার্ম | — | `bh_setup/setup`, `termSave`, `termDelete` |

পেজ খুলবে client দিয়ে: `.../pathshala-web/information/index/bh_report/dashboard`

---

## ৮. হিসাবের সূত্র

| ফিল্ড | সূত্র |
|---|---|
| `indicators[].percent` | period এ ঐ indicator এর রেটিং এর `score` এর গড় |
| `indicators[].rating` | ঐ % এর label (নিচের টেবিল) |
| `overall_percent` | যে indicator এর রেটিং আছে তাদের % এর গড়, ১ দশমিক; রেটিং নেই এমন indicator বাদ |
| Period | চলতি session এর `bh_terms` এর টার্ম; কোনো টার্ম দেওয়া না থাকলে মাস |
| `previous_percent` | আগের টার্মের একই হিসাব; না থাকলে `null` |
| `trend[]` | session এর প্রতিটা টার্মের overall %, `sort_order` অনুযায়ী, পুরনো আগে; `label` = টার্মের `name` |
| Monthly রিপোর্ট | মাসের ১ তারিখ থেকে শেষ দিন |
| Term রিপোর্ট | `bh_terms` এর `start_date` থেকে `end_date` |
| `feedback[]` | `comment` আছে এমন `bh_observations` → subject, teacher, comment, date |
| Total Observations | period এ `bh_observations` (is_deleted=0) |
| Positive Recognition | period এ `bh_recognitions` |
| Improvement Cases | যাদের overall % আগের period এর চেয়ে বেড়েছে |
| Support Required | `support_required=1` এমন observation (period এ) + incident (resolved না) |
| Resolved Cases | `bh_incidents` status=`resolved` (period এ) |

| % | `rating` | বাংলা |
|---|---|---|
| 85–100 | `excellent` | চমৎকার |
| 70–84.9 | `good` | ভালো |
| 55–69.9 | `developing` | উন্নতি হচ্ছে |
| 0–54.9 | `needs_support` | সহায়তা প্রয়োজন |

```sql
-- একজন ছাত্র, একটা period
SELECT r.indicator, AVG(r.score) AS percent, COUNT(*) AS ratings
FROM bh_ratings r
JOIN bh_observations o ON o.id = r.observation_id
WHERE o.student_id = ? AND o.is_deleted = 0
  AND o.observed_on BETWEEN ? AND ?
GROUP BY r.indicator;
-- overall_percent = এই percent গুলোর গড়
```

---

## ৯. মূল লজিক (pseudo)

**7.3 Quick entry**
1. টোকেনের user → teacher_code; ছাত্র teacher scope এ কিনা, না হলে 403
2. `ratings` এ ১+ entry, প্রতিটা key বৈধ indicator, value বৈধ rating, না হলে 422
3. `idempotent('behaviour/quick-entry', ...)` এর ভেতরে transaction: `bh_observations` → প্রতিটা রেটিং `bh_ratings` (score কপি)
4. `support_required=1` হলে `sl_notifications` এ অভিভাবকের push (module `bh`)
5. `{ "data": null }`

**7.4 Recognition**
1. scope চেক; `tag` বৈধ কিনা
2. `bh_recognitions` রো; ছাত্র + অভিভাবকের push (Positive Recognition)

**7.6 Incident লেখা**
1. scope চেক; status = `reported`, `reported_by` = টোকেনের teacher
2. `bh_incidents` + `bh_incident_history` (status `reported`, note = action_taken)
3. `{ "data": { "id": "inc-<id>" } }`

**7.7 Status বদল**
1. শুধু admin (`isAdmin()`), না হলে 403
2. শুধু সামনের দিকে: reported → under_review → resolved; একই status এ শুধু note যোগ করা যাবে
3. `UPDATE bh_incidents SET status=?, follow_up_note=? WHERE id=? AND status=?` — affected_rows দিয়ে race ঠেকানো
4. `bh_incident_history` এ নতুন রো (পুরনো note কখনো overwrite না)

**7.1 Overview**
1. student_id টোকেনের নিজের বা linked সন্তানের কিনা, না হলে 403
2. §৮ এর হিসাব; recognitions, feedback, goals যোগ; **incident বাদ**

---

## ১০. Open Items — সিদ্ধান্ত লাগবে

| # | প্রশ্ন | প্রস্তাব (ডিফল্ট) |
|---|---|---|
| ১ | "Assigned students" মানে কী | class teacher এর section + subject teacher এর class |
| ২ | ~~Period কী: মাস নাকি টার্ম?~~ | **সিদ্ধান্ত হয়েছে (2026-10-26):** `bh_terms` টেবিল; Admin প্রতি session এ টার্মের নাম আর তারিখ দেবেন। টার্ম না থাকলে মাস। |
| ৩ | Important Behaviour Update কখন যাবে | Admin incident resolve করার সময় চাইলে অভিভাবককে একটা সহায়ক বার্তা |
| ৪ | Follow-up Reminder কাকে, কখন | resolved না এমন incident ৩ দিন পুরনো হলে admin এর dashboard এ + push |
| ৫ | ছাত্র/অভিভাবক incident দেখবে কিনা | না (API 7.1 server rule) |
| ৬ | স্কোর 100/75/50/25 আর label সীমা | §৪.১ আর §৮ |
| ৭ | রেটিং কতদিন edit করা যাবে | একই দিনে শিক্ষক; পরে শুধু Admin |
| ৮ | Guardian–student সম্পর্ক | api/v1 এ হোমওয়ার্ক/কুইজ যেভাবে যাচাই করে সেভাবে |

---

## ১১. ধাপে ধাপে বাস্তবায়ন (Phases)

**Phase 1 — Entry + Overview**
- টেবিল: `bh_observations`, `bh_ratings`, `bh_recognitions`, `bh_terms`
- Setup পেজে টার্ম এন্ট্রি; API 7.1, 7.2, 7.3, 7.4; Web: Quick Entry (T1), Student Profile (S1)

**Phase 2 — Incident + Goals**
- টেবিল: `bh_incidents`, `bh_incident_history`, `bh_goals`
- API 7.5, 7.6, 7.7; Web: Incident Log (A2), Guardian ভিউ (G1), goals

**Phase 3 — Dashboard + Report + Notification**
- Admin Dashboard (A1), Report (R1), Excel/PDF, Class Grid (T2), push (sl_notifications), proposal endpoint দুটো

---

## ১২. কোড লেখার আগে চেকলিস্ট

- [ ] §১০ এর বাকি Open Items কনফার্ম
- [x] SQL লোকাল MySQL এ চালানো, ৭টা টেবিল তৈরি, % query ঠিক (2026-10-26)
- [ ] api/v1 এর student/guardian যাচাই আর `isAdmin()` behaviour endpoint এ একইভাবে
- [ ] `class_teacher_info`, `subject_teacher_info` দিয়ে scope query
- [ ] `sl_notifications` টেবিল স্কুলে না থাকলে setup এ তৈরি (Student Leave schema এর একই statement)
- [ ] প্রতিটা controller/model এ CLAUDE.md এর boilerplate হুবহু
- [ ] `controllers/behaviour/`, `models/behaviour/` + `routes.php` এ route
- [ ] PHP 5.6 compatible
- [ ] Sidebar এ "Behaviour" মেনু

---

## ১৩. বাস্তবায়ন (2026-10-26)

### ১৩.১ ফাইল

| ধরন | ফাইল |
|---|---|
| SQL | `application/sql/behaviour_module_schema.sql` (৭টা `bh_` টেবিল + শেয়ার্ড `sl_notifications` এর `CREATE TABLE IF NOT EXISTS`) |
| Model (নতুন) | `models/behaviour/bh_common_model.php`, `bh_entry_model.php`, `bh_incident_model.php`, `bh_report_model.php` |
| Controller (নতুন) | `controllers/behaviour/bh_setup.php`, `bh_entry.php`, `bh_incident.php`, `bh_report.php`, `bh_student.php` |
| View (নতুন) | `views/admin/bh_common/_style.php`, `_scripts.php`; `bh_setup/setup.php`; `bh_entry/quickEntry.php`, `classGrid.php`; `bh_incident/incidentList.php`; `bh_report/dashboard.php`, `report.php`; `bh_student/studentList.php`, `profile.php` |
| বদলানো | `config/routes.php` (bh_ route), `views/admin/include/admin_left_sidebar.php` ("Behaviour" মেনু), `controllers/api/v1.php` (Module 7 section + routing), `views/admin/include/searchable_select.php` (§১৩.৫), `views/admin/student_leave/setup.php` (§১৩.৫) |

### ১৩.২ পেজ (client দিয়ে: `.../information/index/<path>`)

| পেজ | path | কে |
|---|---|---|
| Behaviour Setup (টেবিল + টার্ম) | `bh_setup/setup` | Admin |
| Dashboard (A1) | `bh_report/dashboard` | Admin (পুরো স্কুল), Teacher (নিজের ছাত্র) |
| Quick Entry (T1) | `bh_entry/quickEntry` | Teacher, Admin |
| Class Grid (T2) — শুধু class দিলেই পুরো class, section ঐচ্ছিক | `bh_entry/classGrid` | Teacher, Admin |
| Discipline & Incident Log (A2) | `bh_incident/incidentList` | Teacher (দেখা + লেখা), Admin (status বদল) |
| Behaviour Report (R1) | `bh_report/report` | Teacher, Admin |
| View as Student / Guardian (S1/G1) | `bh_student/studentList` → `bh_student/profile` | Teacher, Admin |

### ১৩.৩ যে সিদ্ধান্তগুলো কোডে নেওয়া হয়েছে

- §১০ এর ডিফল্টগুলো: incident status শুধু Admin বদলান; ছাত্র/অভিভাবক incident দেখে না; রেটিং entry Remove করা যায় একই দিনে নিজে, পরে শুধু Admin (soft delete, `is_deleted`)।
- FR-7.6: Support Required এর অভিভাবক-বার্তায় incident এর কোনো বিবরণ যায় না, শুধু "স্কুল কথা বলতে চায়"। Admin চাইলে 7.7 / web থেকেও flag দিতে পারেন।
- FR-7.3: status বদল, নোট, Action Taken বদল, Parent Contact, Support flag — প্রতিটা `bh_incident_history` তে নতুন রো।
- Follow-up Reminder: আলাদা job নেই; ৩ দিন কোনো বদল না হলে লিস্ট আর Dashboard এ "Follow-up due"।
- Important Behaviour Update: Admin resolve করার সময় টিক দিলে অভিভাবককে ধন্যবাদ-বার্তা।
- নোটিফিকেশন `sl_notifications` এ queue হয় (module `bh`); পাঠানোর job লিভ মডিউলের।
- API id: ছাত্র `"12"`, incident `"inc-12"` (দুটো ফরম্যাটই নেয়)। সময় ISO 8601 (+06:00)।
- টার্ম না থাকলে সব জায়গায় মাস (বাংলা মাসের নাম)।

### ১৩.৪ লোকাল টেস্ট (2026-10-26, `pathshala_borderguardsylhet`)

- API: 7.1 (ছাত্র, অভিভাবক, staff), 7.2 (teacher scope: assigned শিক্ষক ছাত্র পান, অন্য শিক্ষক পান না), 7.3 (Idempotency replay, 422, 403), 7.4, 7.5 (history সহ), 7.6, 7.7 (teacher 403, পেছনে যাওয়া 422, resolve এ note লাগবে), dashboard, reports — সব প্রত্যাশিত ফল।
- হিসাব মিলেছে: Responsibility (100 + 75)/2 = 87.5, Overall = রেট করা ৪টার গড় = 71.9; টার্ম দিলে previous = আগের টার্ম।
- Web: ৮টা পেজ PHP error ছাড়া লোড হয়; quickSave, gridSave, goalSave, termSave (overlap আটকায়), incidentSave, statusChange, incidentView ঠিক কাজ করে।
- বাংলা টেক্সট UTF-8 এ ঠিকমতো সেভ হয়।
- টেস্টের পর টেবিল আর টেস্ট-রো মুছে ফেলা হয়েছে; ডাটাবেস আগের অবস্থায়।

### ১৩.৫ আসল লগইনে চালানোর পর ঠিক করা সমস্যা (2026-10-26)

নতুন মডিউল বানানোর সময়ও এগুলো মনে রাখতে হবে।

| সমস্যা | কারণ | সমাধান |
|---|---|---|
| Setup বাটনে "Not allowed" / "Unexpected response from the server" (Behaviour আর Student Leave দুটোতেই) | AJAX POST এ কোনো field না থাকলে (`{}`) client (`pathshala-web/.../information.php`) `token=` যোগ করে না, তাই সার্ভার ইউজার চেনে না | বাটন এখন `{ run: 1 }` পাঠায় (`bh_setup/setup.php`, `student_leave/setup.php`)। **নিয়ম: client দিয়ে যাওয়া প্রতিটা POST এ অন্তত একটা field থাকবে।** |
| Class Grid এ পুরো class ভরে Save করলে "Unexpected response" | PHP `max_input_vars` = 1000; ১৩৭ ছাত্র × ১০ ঘর = ১৩৭০+ field, শেষের দিকের field (token সহ) বাদ পড়ে যায় | পুরো grid এখন **একটা JSON field** (`grid`) এ যায়; `gridSave` সেটা পড়ে (পুরনো ফরম্যাটও চলে)। ১৩৭ ছাত্র / ১৩৭০ রেটিং ০.৬ সেকেন্ডে সেভ হয়েছে। **নিয়ম: অনেক ঘরের ফর্ম JSON করে পাঠাতে হবে।** |
| Class Grid এ "Load students" চাপলে কিছুই হয় না | Section `required` ছিল; select2 আসল select লুকিয়ে রাখে, তাই ব্রাউজার চুপচাপ submit আটকায় | Section ঐচ্ছিক (না দিলে পুরো class, রো তে section দেখায়), কোনো select এ `required` নেই। **নিয়ম: searchable select এ `required` দেওয়া যাবে না, সার্ভারে চেক করতে হবে।** |
| এক dropdown এ ক্লিক করলে ফর্মের প্রথম dropdown ও খুলে যায় | `assets/custom/js/script.accounts.js` (সব পেজে লোড হয়) focus হলে `.siblings('select')` খোলে; পাশাপাশি থাকা সব select ধরে ফেলে | `searchable_select.php` তার বক্সের focus event উপরে যেতে দেয় না। Homework, Quiz, Leave, Behaviour সবগুলো ঠিক হয়েছে (headless Chrome এ প্রতিটা dropdown ক্লিক করে যাচাই)। Tab দিয়ে এলে Enter / Space / ↓ চেপে খুলতে হয়। |
| Searchable dropdown পুরো সারি নেয়, পাশে সবুজ বক্স | `custom_ehm.css` এ `.select2-container{width:100% !important}`, `custom_theme.css` এ সবুজ arrow | `searchable_select.php` নিজের হিসাব করা width inline `!important` দিয়ে বসায়, arrow ধূসর; সব মডিউলে একরকম |
| Incident popup এ Save বাটন দেখা যায় না | রঙের CSS variable শুধু `.bh` এ ছিল, popup তার বাইরে; theme popup header/footer সবুজ করে | variable `.bh-modal` এও; popup header/footer সাদামাটা; বাটনের নাম "Save follow-up" |
| Behaviour পেজে type করে খোঁজার dropdown ছিল না | — | ৭টা পেজে `admin/include/searchable_select` যোগ; Class Grid এর রেটিং ঘরগুলো `no-search` (শত শত search box হলে ধীর হত); Incident popup `#main_right` এর ভেতরে আনা হয়েছে যাতে তার dropdown ও searchable হয় |

### ১৩.৬ Indicator ও Recognition tag Admin যোগ করতে পারেন (2026-10-28)

§৩.২ এর সিদ্ধান্ত বদলেছে: নতুন টেবিল `bh_options` (`type` = indicator / tag, `code`, `name_en`, `name_bn`, `sort_order`, `is_active`)।

- `bh_setup/setup` এ "Check tables again" চাপলে টেবিল তৈরি হয় আর বর্তমান ১০টা indicator + ৭টা tag দিয়ে ভরে যায় (`seedOptions()`)।
- `bh_common_model` এর constructor (`loadOptions()`) চালু থাকা রো দিয়ে `$INDICATORS` / `$TAGS` বদলে দেয়, তাই সব পেজ, রিপোর্ট আর API 7.3/7.4 এর validation নিজে থেকেই নতুন লিস্ট পায়। টেবিল না থাকলে বা খালি থাকলে আগের ডিফল্ট।
- `code` ইংরেজি নাম থেকে একবার তৈরি হয় (`Kindness` → `kindness`), পরে বদলায় না; নাম, বাংলা নাম, order বদলানো যায়।
- Turn off: entry পেজে দেখায় না, % হিসাব আর রিপোর্ট থেকে বাদ; পুরনো ডেটা থাকে, আবার On করলে ফিরে আসে। পুরনো recognition এ বন্ধ tag এর নাম ঠিকই দেখায় (`label()`)।
- Remove শুধু যেটা কখনো ব্যবহার হয়নি; প্রতিটা type এ অন্তত একটা On থাকতে হবে।
- মোবাইল অ্যাপ: API এখনো code পাঠায়; অ্যাপে নাম hard-code থাকলে নতুন indicator/tag এর নাম দেখাতে API থেকে লিস্ট পড়তে হবে।

### ১৩.৭ এখনো বাকি

- Excel / PDF export (এখন রিপোর্ট পেজে Print আছে)।
- `sl_notifications` থেকে আসল SMS/push পাঠানো (লিভ মডিউলের sender job এর উপর নির্ভর)।
- PHP 5.6 এ লাইভ সার্ভারে একবার চালিয়ে দেখা (লোকালে PHP 7.4)।
- আসল লগইনে Setup, Incident, Class Grid চালানো হয়েছে; বাকি পেজগুলো (Report, Profile, Goals) ও একবার দেখা।
