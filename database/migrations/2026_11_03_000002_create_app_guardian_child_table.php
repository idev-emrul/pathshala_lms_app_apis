<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_guardian_child', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_user_id');
            $table->integer('schl_id');
            $table->string('student_id', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['app_user_id', 'schl_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_guardian_child');
    }
};
