# Pathshala App API

Laravel 12 API-only backend for the Pathshala mobile app. Phase 1 covers student authentication. The full plan and API contract are in [pathshala_app_authentication.md](pathshala_app_authentication.md).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `JWT_SECRET` in `.env` to a long random value, for example the output of `php -r "echo bin2hex(random_bytes(32));"`.

```bash
php artisan migrate   # adds app_user, app_user_log, app_login_attempt and app_migrations to the master DB
php artisan serve     # http://127.0.0.1:8000/api/v1
```

Import [postman/Pathshala_App_API.postman_collection.json](postman/Pathshala_App_API.postman_collection.json) to try the API. Run the requests in order.

## Databases

- **Master:** `db_school_api_web`. This project only adds its own tables. `school_information` is read-only, and the existing `migrations` table belongs to another project, so migrations are tracked in `app_migrations`.
- **School databases:** connected at runtime from `school_information.db_name`. The connection is opened in MySQL read-only mode, so this API cannot write to them.

Never run `migrate:fresh` or `db:wipe` on the master database. They drop every table, including `school_information`.

## Scheduled job

`otp:prune` removes `app_login_attempt` records whose hour has passed. It runs from the Laravel scheduler, so add a Windows Task Scheduler entry that runs this every minute:

```bash
php artisan schedule:run
```

Sending an OTP also clears an old lock, so locks still end on time if the job is late.

## Activity log

Saved in one file per month at `storage/logs/activity/activity_YYYY_MM.log`. To read a month:

```bash
php artisan activity:read 2026 11
php artisan activity:read 2026 11 --user=1
```

## Adding an SMS gateway

1. Create a class that implements `App\Services\Otp\OtpDriver`.
2. Add it to the driver list in `App\Providers\AppServiceProvider`.
3. In `.env`, set `OTP_DRIVER` to it and remove `OTP_FIXED`.

**While `OTP_FIXED` is set, anyone can pass OTP verification with that value.** Do not go live with it. No app change is needed when it is removed.

## Tests

Tests need their own empty database. They refuse to run on any other master database, and only read from the school database.

```bash
mysql -u root -e "CREATE DATABASE pathshala_app_test"
php vendor/bin/phpunit
```
