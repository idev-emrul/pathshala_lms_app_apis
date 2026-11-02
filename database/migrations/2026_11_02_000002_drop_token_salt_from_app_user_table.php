<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saved tokens were signed with the old key, so PIN login must issue new ones.
        DB::table('app_user')->update(['auth_token' => null]);

        Schema::table('app_user', function (Blueprint $table) {
            $table->dropColumn('token_salt');
        });
    }

    public function down(): void
    {
        Schema::table('app_user', function (Blueprint $table) {
            $table->string('token_salt', 64)->nullable()->after('auth_token');
        });
    }
};
