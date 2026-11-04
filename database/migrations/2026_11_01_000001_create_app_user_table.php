<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user', function (Blueprint $table) {
            $table->id();
            $table->string('mobile_number', 15);
            $table->string('role', 20);
            $table->string('verified_id', 20)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('verified_by', 20)->nullable();
            $table->integer('schl_id')->nullable();
            $table->string('school_name', 150)->nullable();
            $table->integer('class_code')->nullable();
            $table->string('class_name', 100)->nullable();
            $table->string('entered_student_id', 20)->nullable();
            $table->string('pin');
            $table->text('auth_token')->nullable();
            $table->string('token_salt', 64);
            $table->string('language_mode', 5)->default('en');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile_number', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user');
    }
};
