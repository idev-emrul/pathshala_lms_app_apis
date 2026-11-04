<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GuardianAuthController;
use App\Http\Controllers\Auth\StudentAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
use App\Http\Controllers\Guardian\GuardianChildController;
use App\Http\Controllers\Guardian\GuardianHomeworkController;
use App\Http\Controllers\HwAssignment\StudentHomeworkController;
use App\Http\Controllers\HwAssignment\TeacherHomeworkController;
use App\Http\Controllers\Quiz\StudentQuizController;
use App\Http\Controllers\Quiz\TeacherQuizController;
use App\Http\Controllers\SchoolController;
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

    Route::post('auth/guardian/check', [GuardianAuthController::class, 'check']);
    Route::post('auth/guardian/send-child-otp', [GuardianAuthController::class, 'sendChildOtp']);
    Route::post('auth/guardian/verify-child-otp', [GuardianAuthController::class, 'verifyChildOtp']);

    Route::post('auth/set-pin', [AuthController::class, 'setPin']);
    Route::post('auth/pin-login', [AuthController::class, 'pinLogin']);

    Route::get('auth/me', [AuthController::class, 'me'])->middleware(['jwt.app', 'tenant']);
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware('jwt.app');
});

Route::middleware(['throttle:auth', 'jwt.app', 'guardian'])
    ->prefix('guardian/children')
    ->controller(GuardianChildController::class)
    ->group(function () {
        Route::post('check', 'check');
        Route::post('send-otp', 'sendOtp');
        Route::post('verify-otp', 'store')->middleware('activity:add_child');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'guardian'])
    ->prefix('guardian')
    ->whereNumber('childId')
    ->controller(GuardianChildController::class)
    ->group(function () {
        Route::get('dashboard', 'dashboard');
        Route::get('children', 'index');
        Route::get('children/{childId}/dashboard', 'show');
        Route::delete('children/{childId}', 'destroy')->middleware('activity:remove_child');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'guardian', 'cache.headers:private;etag'])
    ->prefix('guardian/children/{childId}/homework')
    ->whereNumber('childId')
    ->controller(GuardianHomeworkController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::get('summary', 'summary');
    });

// homework:student and homework:teacher work out who is calling, and quizzes need the same.
// A guardian can read a child's quizzes on the same paths, and nothing else.
Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'homework:student,guardian'])
    ->prefix('students/{studentId}/quizzes')
    ->whereNumber(['studentId', 'quizId'])
    ->controller(StudentQuizController::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('cache.headers:private;etag');
        Route::get('{quizId}', 'show');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'homework:student'])
    ->prefix('students/{studentId}/quizzes')
    ->whereNumber(['studentId', 'quizId'])
    ->controller(StudentQuizController::class)
    ->group(function () {
        Route::post('{quizId}/accept', 'accept')->middleware('activity:accept_quiz');
        Route::post('{quizId}/attempt', 'start')->middleware('activity:start_quiz_attempt');
        Route::post('{quizId}/attempt/submit', 'submit')->middleware('activity:submit_quiz');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'homework:teacher'])
    ->prefix('teacher/quizzes')
    ->whereNumber('quizId')
    ->controller(TeacherQuizController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('activity:create_quiz');
        Route::get('{quizId}/roster', 'roster');
        Route::put('{quizId}/marks', 'saveMarks')->middleware('activity:save_quiz_marks');
        Route::post('{quizId}/release', 'release')->middleware('activity:release_quiz');
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
