<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Tests write to the pathshala_app_test database only. Student data is read from
 * the real school database through the app's read-only tenant connection.
 */
abstract class TestCase extends BaseTestCase
{
    protected const SCHOOL_ID = 8;
    protected const SCHOOL_DB = 'pathshala_borderguardsylhet';
    protected const MOBILE = '01700000001';

    private static bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection('master')->getDatabaseName() !== 'pathshala_app_test') {
            throw new RuntimeException('Tests must only run on the pathshala_app_test database.');
        }

        if (! self::$prepared) {
            $this->prepareDatabase();
            self::$prepared = true;
        }

        foreach (['app_user', 'app_user_log', 'app_login_attempt', 'app_guardian_child'] as $table) {
            DB::connection('master')->table($table)->truncate();
        }

        $this->withoutMiddleware(ThrottleRequests::class);

        config(['pathshala.activity_log_path' => storage_path('framework/testing/activity')]);
        File::deleteDirectory(config('pathshala.activity_log_path'));
    }

    private function prepareDatabase(): void
    {
        Artisan::call('migrate', ['--force' => true]);

        if (! Schema::connection('master')->hasTable('school_information')) {
            Schema::connection('master')->create('school_information', function (Blueprint $table) {
                $table->integer('schl_id', true);
                $table->string('schl_name', 150)->nullable();
                $table->string('schl_url', 100);
                $table->string('schl_code', 20)->nullable();
                $table->string('api_code', 40);
                $table->string('school_secret', 100)->nullable();
                $table->string('db_name', 100)->nullable();
            });
        }

        DB::connection('master')->table('school_information')->truncate();
        DB::connection('master')->table('school_information')->insert([
            [
                'schl_id' => self::SCHOOL_ID,
                'schl_name' => 'Test School',
                'schl_url' => 'http://example.test',
                'schl_code' => 'school_one',
                'api_code' => 'secret-api-code',
                'school_secret' => 'secret-school-secret',
                'db_name' => self::SCHOOL_DB,
            ],
            [
                'schl_id' => 9,
                'schl_name' => 'School Without Database',
                'schl_url' => 'http://example.test',
                'schl_code' => 'no_db',
                'api_code' => 'secret-api-code',
                'school_secret' => null,
                'db_name' => null,
            ],
        ]);
    }
}
