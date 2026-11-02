# Pathshala App API

**Project Plan, Database Design & Authentication API Contract**

Version 1.0 · 1 November 2026 · Phase 1: Student authentication

## 1. Overview

A new **Laravel 12 API-only project** that serves the backend for the Pathshala mobile app. The app UI is already built; this project provides real data and authentication.

- Separate code from the live Pathshala project, but uses the **same databases**.
- All responses are **JSON only**.
- Authentication uses **JWT** with a second encryption layer. **Tokens never expire.**
- Phase 1 covers the **Student** role. Teacher and the other 3 roles come later.

### Environment

| Item | Value |
| --- | --- |
| Project folder | `D:\laragon\www\pathshala_app` |
| Server | Laragon on Windows, MySQL 8.0.30 on localhost |
| PHP / Composer | PHP 8.2, Composer 2.7, Laravel 12 |
| Master DB | `db_school_api_web` |
| Test school | `schl_id` = 8, `schl_code` = `school_one`, DB `pathshala_borderguardsylhet` |

## 2. Build Steps

| # | Step | Result |
| --- | --- | --- |
| 1 | Create the Laravel 12 API-only project | Fresh project, API routes only |
| 2 | Configure DB connections | master → `db_school_api_web`; tenant switched at runtime from `school_information.db_name` |
| 3 | Install and configure JWT | No-expiry tokens, encrypted `db_name` claim, per-user salt |
| 4 | Migrations (master DB only) | New tables `app_user`, `app_user_log` and `app_login_attempt`. Existing tables untouched |
| 5 | Services | `OtpService`, `SchoolDbService`, `StudentAuthService`, `TokenService`, `ActivityLogService` |
| 6 | Middleware | `jwt.app` (checks token and salt), `tenant` (connects the school DB) |
| 7 | Response helper | Same JSON envelope on every endpoint |
| 8 | Auth endpoints | Full student login flow, including guest |
| 9 | Testing | Verified, wrong class, forgot password, guest and "Others" cases |
| 10 | Postman collection | Ready for the app team |

**Not in this phase:** teacher flow, the other 3 roles, SMS gateway, home-page data APIs.

## 3. Databases

### 3.1 Master database: db_school_api_web

Table `school_information` (existing, read-only):

| Column | Type | Notes |
| --- | --- | --- |
| `schl_id` | int, PK | School id, stored in `app_user.schl_id` |
| `schl_name` | varchar(150) | Shown in the school list |
| `schl_url` | varchar(100) | Website |
| `schl_code` | varchar(20) | Used to trace the school DB connection |
| `api_code` | varchar(40) | Secret, never exposed |
| `school_secret` | varchar(100) | Secret, never exposed |
| `db_name` | varchar(100) | School database name, never exposed in plain text |

- The school list returns only `schl_id`, `schl_name`, `schl_code`. Schools with no `db_name` are skipped.
- Flow: `schl_id` → `db_name` → open the tenant connection.

### 3.2 School (tenant) databases

One database per school, on the same MySQL server and credentials. The API never runs migrations or writes on these databases.

**`student_info`**: student identity and login

| Column | Use |
| --- | --- |
| `student_registration_code` | The Student ID the user types (e.g. 105001) |
| `student_auth` | Student password, stored as a plain 6-character code; compared directly |
| `contact_number` | Registered mobile for the forgot-password OTP. 224 of 3614 students have none, so guest login is offered |
| `student_name`, `student_name_bn`, `father_name`, `mother_name` … | Profile data |

**`student_current_status_info`**: the student's class placement

- Key columns: `session_code`, `class_code`, `section_code`, `shift_code`, `group_code`, `roll_number`, `active_status`.
- A student can have more than one row. Only `active_status = 1` counts.
- **Current class** = the active row in a running session (`session_info.running_session = 1`). If there are several, the highest `session_code` wins.

**Lookup tables**

| Table | Key → Name |
| --- | --- |
| `class_info` | `class_code` → `class_name` / `class_name_bn` (active only, ordered by `class_position`) |
| `section_info` | `section_code` → `section_name` |
| `shift_info` | `shift_code` → `shift_name` |
| `group_info` | `group_code` → `group_name` |
| `session_info` | `session_code` → `session_name`, `running_session` |

### 3.3 New table: app_user (master DB)

| Column | Purpose |
| --- | --- |
| `id` | Primary key |
| `mobile_number` | First number entered, verified by OTP |
| `role` | student, teacher (3 more roles later) |
| `verified_id` | Verified identity (`student_registration_code` or teacher id). Null for guests |
| `is_verified` | 1 = verified with the school DB, 0 = guest |
| `schl_id` | School id. Null when the school is "Others" |
| `school_name` | From the master DB, or typed by the user |
| `class_code` / `class_name` | Selected class |
| `entered_student_id` | The Student ID the user typed (kept for guests too) |
| `pin` | App PIN, hashed |
| `auth_token` | Current JWT. Its payload carries the encrypted `db_name` |
| `token_salt` | Random per-user salt. Changing it logs the user out everywhere |
| `last_login_at`, timestamps | |
| language mode | |
| `verified_by` | |

#### App_user_log (table, master DB)

One row is added for each login, logout, PIN reset and number reset. Rows are only added, never changed.

| Column | Purpose |
| --- | --- |
| `id` | Primary key |
| `app_user_id` | The user, from `app_user.id` |
| `action` | `login`, `logout`, `pin_reset` or `number_reset` |
| `mobile_number` | The user's number at the time; the new number for `number_reset` |
| `old_mobile_number` | The previous number. Only for `number_reset`, otherwise null |
| `ip_address` | IP address of the request |
| `user_agent` | Device or app details of the request |
| `created_at` | When the event happened |

- `login` is written at `/auth/set-pin` and `/auth/pin-login`; `logout` is written at `/auth/logout`.
- Never stored: PIN, OTP, tokens.

#### Activity_log (monthly file, not a table)

The app user's activity is saved in monthly files, not in a database table.

- A new file is created automatically for each month, e.g. `storage/logs/activity/activity_2026_11.log`.
- Each activity is added as one line of JSON at the end of the current month's file.
- `ActivityLogService` writes the entries and reads them back by month, with an optional `app_user_id` filter. A month with no file returns an empty list.
- Files are kept outside the public folder. Never written: PIN, OTP, passwords, tokens, `db_name`.

| Field | Purpose |
| --- | --- |
| `time` | Date and time of the activity |
| `app_user_id` | The user, from `app_user.id` |
| `role` | The user's role |
| `action` | What the user did, e.g. `view_profile` |
| `details` | Extra data for the activity. Can be empty |
| `ip_address` | IP address of the request |

### 3.5 New table: app_login_attempt (master DB)

Login attempts are recorded here, with one record for each `mobile_number` and `purpose`.

`mobile_number`, `purpose` (`mobile_login` / `student_contact`), `otp`, `wrong_attempts`, `send_count`, `locked_at`, `expires_at`, timestamps.

- Resend is locked after 2 wrong entries on an OTP, or when both OTPs have expired unused. The lock starts at the 2nd wrong entry or when the 2nd OTP expires, and is saved in `locked_at`.
- The record is removed 1 hour after the lock. The user can then request a new OTP with fresh counters.
- The record is deleted as soon as the correct OTP is entered.
- A scheduled job removes these records. Sending an OTP also removes a record whose lock is over 1 hour old, so the lock ends on time even if the job runs late.

## 4. Student Login Flow

1. Select role → **Student**.
2. Enter a mobile number → OTP sent.
3. Verify OTP → mobile verified.
4. Select school, class and Student ID.
5. Set PIN → JWT issued → Home page.

### At step 4: school is in the list

1. The API finds the school's database through the master DB.
2. It checks that the Student ID exists **and** that the student's current class matches the selected class.
3. **Not found or wrong class** → "Verification failed". The user can retry or **continue as guest**. The real class is never shown.
4. **Found** → the user enters the student password.
   - **Password correct** → **Verified** → Set PIN.
   - **Forgot password** → OTP sent to the student's `contact_number`, shown masked: OTP sent to 018\*\*\*\*\*935. OTP correct → **Verified** → Set PIN.
   - **No contact number, or the OTP fails** → continue as **guest** → Set PIN.

### At step 4: school is not in the list ("Others")

The user types the school name, selects a class and enters a Student ID → logs in as **guest** → Set PIN.

### Flow token

Between steps the API returns a short-lived signed `flow_token` that the app must send to the next step. This proves earlier steps really happened, so the app cannot skip a step.

## 5. OTP Rules

| Rule | Value |
| --- | --- |
| OTP value (now) | **123456**. No SMS gateway yet; switched off later in `.env` |
| Valid for | **30 minutes** |
| Wrong entries allowed | **2**. After the 2nd wrong entry that OTP is dead and the user is locked |
| Total sends | **2**: the first send and 1 resend |
| When resend is allowed | Only after the current OTP's 30 minutes end |
| Resend after 2 sends | Message: "OTP limit reached. Please check your number and try again later." |
| Counters | Separate for the mobile login OTP and the student contact OTP |
| Lock | 2 wrong entries on an OTP, or both OTPs expired unused. The record is removed 1 hour after the lock |
| After the correct OTP | The record is deleted |

OTPs are stored hashed. Sending sits behind `OtpService` with a swappable driver (`OTP_DRIVER=log`, `OTP_FIXED=123456`), so a real SMS gateway can be added later without changing the app.

## 6. Security

- **JWT with no expiry**: `ttl = null`, `exp` claim not required.
- **Double encryption, layer 1**: `db_name` and `schl_id` are encrypted with Laravel Crypt (AES-256) before they go into the token.
- **Double encryption, layer 2**: the JWT is signed, and it carries the user's `token_salt`, which must match the database on every request. Logout or a PIN reset changes the salt, so old tokens stop working.
- PIN and OTP are hashed with bcrypt.
- Rate limiting on all auth endpoints. Phone numbers are always masked.
- Never returned: `student_auth`, PIN, OTP, `db_name`, `api_code`, `school_secret`, NID numbers.

## 7. Auth API Contract

Base URL: `http://<host>/api/v1`. Every response uses this envelope:

```json
{ "status": true, "message": "...", "data": { } }
```

Errors return `"status": false`, a message, `errors` for validation, and the correct HTTP code.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/auth/send-otp` | Send or resend the mobile OTP |
| POST | `/auth/verify-otp` | Verify the mobile OTP |
| GET | `/schools` | School list |
| GET | `/schools/{schl_id}/classes` | Class list for a school |
| POST | `/auth/student/check` | Check Student ID and class |
| POST | `/auth/student/verify-password` | Check the student password |
| POST | `/auth/student/send-contact-otp` | OTP to the student's contact number |
| POST | `/auth/student/verify-contact-otp` | Verify that OTP |
| POST | `/auth/guest` | Continue as guest |
| POST | `/auth/set-pin` | Save PIN and issue the JWT |
| POST | `/auth/pin-login` | Unlock with PIN |
| GET / POST | `/auth/me`, `/auth/logout` | Profile / logout (Bearer token) |

### 7.1 POST /auth/send-otp

```jsonc
// request
{ "mobile_number": "01758494584", "role": "student" }

// 200
{ "status": true, "message": "OTP sent to 017*****584",
  "data": { "expires_in_seconds": 1800, "resend_left": 1 } }

// 429 - current OTP still valid
{ "status": false, "message": "Please wait before requesting a new OTP",
  "data": { "retry_after_seconds": 1520 } }

// 429 - locked
{ "status": false,
  "message": "OTP limit reached. Please check your number and try again later.",
  "data": { "retry_after_seconds": 3600 } }
```

### 7.2 POST /auth/verify-otp

```jsonc
// request
{ "mobile_number": "01758494584", "otp": "123456" }

// 200
{ "status": true, "message": "Mobile verified", "data": { "flow_token": "..." } }

// 422 - wrong
{ "status": false, "message": "Incorrect OTP", "data": { "attempts_left": 1 } }

// 422 - expired
{ "status": false, "message": "OTP expired. Please request a new OTP." }

// 422 - 2nd wrong (locked)
{ "status": false, "message": "OTP blocked. Please try again after 1 hour.", "data": { "retry_after_seconds": 3600 } }
```

### 7.3 GET /schools and GET /schools/{schl_id}/classes

```jsonc
// GET /schools
{ "status": true,
  "data": [ { "schl_id": 8, "schl_name": "PATHSHALA MULTI ...", "schl_code": "school_one" } ] }

// GET /schools/8/classes   (class_name_bn holds the Bangla name)
{ "status": true,
  "data": [ { "class_code": 13, "class_name": "KG",  "class_name_bn": "..." },
            { "class_code": 10, "class_name": "One", "class_name_bn": "..." } ] }
```

### 7.4 POST /auth/student/check

```jsonc
// request
{ "flow_token": "...", "schl_id": 8, "class_code": 6, "student_id": "105002" }

// 200 - found
{ "status": true, "message": "Student found",
  "data": { "flow_token": "...", "student_name": "...", "has_contact_number": true } }

// 404 - not found or wrong class
{ "status": false, "message": "Verification failed",
  "data": { "can_continue_as_guest": true } }
```

### 7.5 POST /auth/student/verify-password

```jsonc
// request
{ "flow_token": "...", "password": "******" }

// 200
{ "status": true, "message": "Student verified", "data": { "flow_token": "...", "next": "set_pin" } }

// 422
{ "status": false, "message": "Incorrect password" }
```

### 7.6 POST /auth/student/send-contact-otp and verify-contact-otp

Same OTP rules as 7.1 and 7.2, but the OTP goes to the student's `contact_number`.

```jsonc
// send - 200
{ "status": true, "message": "OTP sent to 017*****584", "data": { "expires_in_seconds": 1800, "resend_left": 1 } }

// send - 422 when no contact number
{ "status": false, "message": "No contact number found", "data": { "can_continue_as_guest": true } }

// verify request
{ "flow_token": "...", "otp": "123456" }

// verify - 200
{ "status": true, "message": "Student verified", "data": { "flow_token": "...", "next": "set_pin" } }
```

### 7.7 POST /auth/guest

```jsonc
// "Others"
{ "flow_token": "...", "school_name": "ABC High School", "class_name": "Six", "student_id": "1234" }

// school from the list
{ "flow_token": "...", "schl_id": 8, "class_code": 6, "student_id": "105002" }

// 200
{ "status": true, "message": "Continue as guest", "data": { "flow_token": "...", "next": "set_pin" } }
```

### 7.8 POST /auth/set-pin

```jsonc
// request
{ "flow_token": "...", "pin": "1234", "pin_confirmation": "1234" }

// 200
{ "status": true, "message": "Login successful",
  "data": { "token": "<jwt>", "token_type": "bearer",
            "user": { "id": 1, "role": "student", "is_verified": true,
                      "school_name": "...", "class_name": "Five", "student_name": "..." } } }
```

### 7.9 POST /auth/pin-login, GET /auth/me, POST /auth/logout

```jsonc
// pin-login request
{ "mobile_number": "01758494584", "role": "student", "pin": "1234" }
```

`/auth/me` and `/auth/logout` need the header `Authorization: Bearer <jwt>`. Logout changes the user's `token_salt`, so the old token stops working.

## 8. Test Cases

| Case | Input | Expected |
| --- | --- | --- |
| Verified student | School 8, correct class, valid ID, correct password | Verified → Set PIN |
| Wrong class | School 8, valid ID, another class | Verification failed → guest option |
| Unknown ID | School 8, ID 999999 | Verification failed → guest option |
| Forgot password | Valid ID → contact OTP 123456 | Verified → Set PIN |
| No contact number | Student without `contact_number` | Guest option |
| "Others" school | Typed school name | Guest → Set PIN |
| OTP wrong twice | Two wrong OTPs | OTP blocked, locked for 1 hour |
| Third send | Resend after 2 sends | "OTP limit reached…" message |
| Lock removed | OTP locked, new OTP requested 1 hour later | Record removed, OTP sent, counters start again |
| Both OTPs expired | Both OTPs expire unused | Locked, record removed after 1 hour |
| OTP verified | Correct OTP | Record deleted |
