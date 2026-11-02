<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->string('pin')->nullable()->change();
            $table->string('token_salt', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Users who verified a mobile but never set a PIN cannot exist under the old columns.
        DB::table('app_user')->whereNull('pin')->orWhereNull('token_salt')->delete();

        Schema::table('app_user', function (Blueprint $table) {
            $table->string('pin')->nullable(false)->change();
            $table->string('token_salt', 64)->nullable(false)->change();
        });
    }
};
