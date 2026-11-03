<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\StudentAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
use App\Http\Controllers\ClassRoutine\StudentRoutineController;
use App\Http\Controllers\ClassRoutine\TeacherRoutineController;
use App\Http\Controllers\HwAssignment\StudentHomeworkController;
use App\Http\Controllers\HwAssignment\TeacherHomeworkController;
use App\Http\Controllers\Notice\NoticeAudienceController;
use App\Http\Controllers\Notice\NoticeController;
use App\Http\Controllers\ResultReport\StudentResultController;
use App\Http\Controllers\ResultReport\TeacherResultController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\StudentProfile\StudentProfileController;
use App\Http\Controllers\StudentProfile\TeacherClassListController;
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

    Route::post('auth/teacher/verify', [TeacherAuthController::class, 'verify']);
    Route::post('auth/teacher/guest', [TeacherAuthController::class, 'guest']);

    Route::post('auth/set-pin', [AuthController::class, 'setPin']);
    Route::post('auth/pin-login', [AuthController::class, 'pinLogin']);

    Route::get('auth/me', [AuthController::class, 'me'])->middleware(['jwt.app', 'tenant']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('jwt.app');
});

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'homework:student'])
    ->prefix('students/{studentId}/homework')
    ->whereNumber(['studentId', 'homeworkId'])
    ->controller(StudentHomeworkController::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('cache.headers:private;etag');
        Route::get('summary', 'summary')->middleware('cache.headers:private;etag');
        Route::post('{homeworkId}/submissions', 'submit')->middleware('activity:submit_homework');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'homework:teacher'])
    ->prefix('teacher')
    ->whereNumber(['homeworkId', 'studentId'])
    ->controller(TeacherHomeworkController::class)
    ->group(function () {
        Route::get('teaching-options', 'teachingOptions');
        Route::get('homework/filter-options', 'filterOptions');
        Route::get('homework/dashboard', 'dashboard');
        Route::get('homework', 'index');
        Route::post('homework', 'store')->middleware('activity:create_homework');
        Route::get('homework/{homeworkId}', 'show');
        Route::match(['patch', 'post'], 'homework/{homeworkId}', 'update')->middleware('activity:update_homework');
        Route::delete('homework/{homeworkId}', 'destroy')->middleware('activity:delete_homework');
        Route::get('homework/{homeworkId}/submissions', 'roster');
        Route::get('homework/{homeworkId}/submissions/{studentId}', 'work');
        Route::post('homework/{homeworkId}/submissions/{studentId}/assessment', 'assess')->middleware('activity:assess_homework');
        Route::post('homework/{homeworkId}/submissions/{studentId}/resubmission', 'requestResubmission')->middleware('activity:request_resubmission');
    });

// Module 3 - Class Routine
Route::middleware(['throttle:routine', 'jwt.app', 'tenant', 'routine:student'])
    ->get('students/{studentId}/routine', [StudentRoutineController::class, 'show'])
    ->whereNumber('studentId');

Route::middleware(['throttle:routine', 'jwt.app', 'tenant', 'routine:teacher'])
    ->get('teachers/{teacherId}/routine', [TeacherRoutineController::class, 'show'])
    ->whereNumber('teacherId');

// Module 1 - Student Full Profile
Route::middleware(['throttle:profile', 'jwt.app', 'tenant', 'profile'])
    ->get('students/{studentId}/profile', [StudentProfileController::class, 'show'])
    ->whereNumber('studentId');

Route::middleware(['throttle:profile', 'jwt.app', 'tenant', 'profile:student', 'activity:request_profile_change'])
    ->post('students/{studentId}/profile/change-requests', [StudentProfileController::class, 'changeRequest'])
    ->whereNumber('studentId');

Route::middleware(['throttle:profile', 'jwt.app', 'tenant', 'profile:teacher'])
    ->get('teacher/my-class/students', [TeacherClassListController::class, 'index']);

// Module 2 - Result Performance Report
Route::middleware(['throttle:result', 'jwt.app', 'tenant', 'result'])
    ->get('students/{studentId}/results', [StudentResultController::class, 'index'])
    ->whereNumber('studentId');

Route::middleware(['throttle:result', 'jwt.app', 'tenant', 'result:teacher'])
    ->prefix('teacher/my-class/exams')
    ->whereNumber(['examId', 'studentId'])
    ->controller(TeacherResultController::class)
    ->group(function () {
        Route::get('/', 'exams');
        Route::get('{examId}', 'exam');
        Route::put('{examId}/students/{studentId}/comment', 'comment')->middleware('activity:comment_result');
    });

// Module 5 - Notice System
Route::middleware(['throttle:notice', 'jwt.app', 'tenant'])
    ->prefix('notices')
    ->group(function () {
        Route::get('/', [NoticeController::class, 'index'])->middleware('notice');
        Route::post('/', [NoticeController::class, 'store'])->middleware(['notice:teacher', 'activity:publish_notice']);
        Route::get('audience-options', [NoticeAudienceController::class, 'index'])->middleware('notice:teacher');
        Route::post('{noticeId}/read', [NoticeController::class, 'read'])->middleware('notice')->where('noticeId', '(web-)?[0-9]+');
    });
