<?php

use App\Http\Controllers\Attendance\StudentAttendanceController;
use App\Http\Controllers\Attendance\TeacherAttendanceController;
use App\Http\Controllers\Auth\AdminStaffAuthController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GuardianAuthController;
use App\Http\Controllers\Auth\StudentAuthController;
use App\Http\Controllers\Auth\TeacherAuthController;
use App\Http\Controllers\ClassRoutine\StudentRoutineController;
use App\Http\Controllers\ClassRoutine\TeacherRoutineController;
use App\Http\Controllers\Guardian\GuardianChildController;
use App\Http\Controllers\Guardian\GuardianHomeworkController;
use App\Http\Controllers\HwAssignment\StudentHomeworkController;
use App\Http\Controllers\HwAssignment\TeacherHomeworkController;
use App\Http\Controllers\Leave\EarlyLeaveController;
use App\Http\Controllers\Leave\LeaveController;
use App\Http\Controllers\Notice\NoticeAudienceController;
use App\Http\Controllers\Notice\NoticeController;
use App\Http\Controllers\Quiz\StudentQuizController;
use App\Http\Controllers\Quiz\TeacherQuizController;
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

    Route::post('auth/guardian/check', [GuardianAuthController::class, 'check']);
    Route::post('auth/guardian/send-child-otp', [GuardianAuthController::class, 'sendChildOtp']);
    Route::post('auth/guardian/verify-child-otp', [GuardianAuthController::class, 'verifyChildOtp']);

    Route::post('auth/{role}/verify', [AdminStaffAuthController::class, 'verify'])->whereIn('role', ['admin', 'staff', 'schoolAdmin', 'accounts']);

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

// Module 11, leave applications. Paths take 3 or the contract's lv-3.
Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:student'])
    ->prefix('students/{studentId}/leave')
    ->whereNumber('studentId')
    ->controller(LeaveController::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('cache.headers:private;etag');
        Route::post('/', 'store')->middleware('activity:apply_leave');
        Route::get('{applicationId}', 'show')->where('applicationId', '(lv-)?[0-9]+');
        Route::post('{applicationId}/withdraw', 'withdraw')->where('applicationId', '(lv-)?[0-9]+')->middleware('activity:withdraw_leave');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:staff'])
    ->prefix('leave/review')
    ->controller(LeaveController::class)
    ->group(function () {
        Route::get('/', 'review');
        Route::post('{applicationId}/decision', 'decide')->where('applicationId', '(lv-)?[0-9]+')->middleware('activity:decide_leave');
    });

// Module 12, early leave. Paths take 3 or the contract's el-3.
Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:student'])
    ->prefix('students/{studentId}/early-leave')
    ->whereNumber('studentId')
    ->controller(EarlyLeaveController::class)
    ->group(function () {
        Route::get('/', 'index')->middleware('cache.headers:private;etag');
        Route::post('/', 'store')->middleware('activity:create_early_leave');
        Route::get('{requestId}', 'show')->where('requestId', '(el-)?[0-9]+');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:approver'])
    ->prefix('early-leave')
    ->controller(EarlyLeaveController::class)
    ->group(function () {
        Route::get('queue', 'queue');
        Route::post('{requestId}/decision', 'decide')->where('requestId', '(el-)?[0-9]+')->middleware('activity:decide_early_leave');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:staff'])
    ->controller(EarlyLeaveController::class)
    ->group(function () {
        Route::get('gate/early-leave', 'gate');
        Route::post('early-leave/{requestId}/release', 'release')->where('requestId', '(el-)?[0-9]+')->middleware('activity:release_early_leave');
    });

Route::middleware(['throttle:homework', 'jwt.app', 'tenant', 'leave:admin'])
    ->group(function () {
        Route::get('admin/leave/summary', [LeaveController::class, 'summary']);
        Route::get('admin/early-leave/summary', [EarlyLeaveController::class, 'summary']);
        Route::get('early-leave/register', [EarlyLeaveController::class, 'register']);
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

// Module 10 - Students' Live Attendance Status (10.4, the PDF, is built by the app team)
Route::middleware(['throttle:attendance', 'jwt.app', 'tenant', 'attendance:student'])
    ->prefix('students/{studentId}/attendance')
    ->whereNumber('studentId')
    ->controller(StudentAttendanceController::class)
    ->group(function () {
        Route::get('/', 'month');
        Route::get('report', 'report');
        Route::get('summary', 'summary');
    });

Route::middleware(['throttle:attendance', 'jwt.app', 'tenant', 'attendance:teacher'])
    ->controller(TeacherAttendanceController::class)
    ->group(function () {
        Route::get('teacher/attendance/register', 'show');
        Route::post('teacher/attendance/register', 'store')->middleware('activity:submit_attendance');
    });
