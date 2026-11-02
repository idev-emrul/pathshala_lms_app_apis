<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_login_attempt', function (Blueprint $table) {
            $table->id();
            $table->string('mobile_number', 15);
            $table->string('purpose', 20);
            $table->string('otp')->nullable();
            $table->unsignedTinyInteger('wrong_attempts')->default(0);
            $table->unsignedTinyInteger('send_count')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['mobile_number', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_login_attempt');
    }
};
