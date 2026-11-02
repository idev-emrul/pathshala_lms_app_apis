<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\StudentAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function () {
    Route::post('auth/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('auth/verify-otp', [AuthController::class, 'verifyOtp']);

    Route::get('schools', [SchoolController::class, 'index']);
    Route::get('schools/{schlId}/classes', [SchoolController::class, 'classes'])->whereNumber('schlId');

    Route::post('auth/student/check', [StudentAuthController::class, 'check']);
    Route::post('auth/student/verify-password', [StudentAuthController::class, 'verifyPassword']);
    Route::post('auth/student/send-contact-otp', [StudentAuthController::class, 'sendContactOtp']);
    Route::post('auth/student/verify-contact-otp', [StudentAuthController::class, 'verifyContactOtp']);
    Route::post('auth/guest', [StudentAuthController::class, 'guest']);

    Route::post('auth/set-pin', [AuthController::class, 'setPin']);
    Route::post('auth/pin-login', [AuthController::class, 'pinLogin']);

    Route::get('auth/me', [AuthController::class, 'me'])->middleware(['jwt.app', 'tenant']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('jwt.app');
});
