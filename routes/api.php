<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\SettingController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::get('/books', [BookController::class, 'index']);
Route::get('/books/search/{keyword}', [BookController::class, 'search']);
Route::get('/books/available', [BookController::class, 'getAvailableBooks']);
Route::get('/books/borrowed', [BookController::class, 'getBorrowedBooks']);
Route::get('/books/lookup/{barcode}', [BookController::class, 'lookup']);
Route::get('/books/lookup-isbn/{isbn}', [BookController::class, 'lookupIsbn']);
Route::get('/students/{studentId}/clearance', [BookController::class, 'checkClearance']);

// Public Settings (for circulation display)
Route::get('/settings/circulation', [SettingController::class, 'circulation']);

// Public (Kiosk) Routes
Route::prefix('public')->group(function () {
    Route::get('/books', [App\Http\Controllers\PublicBookController::class, 'index']);
    Route::get('/books/categories', [App\Http\Controllers\PublicBookController::class, 'categories']); // New route
    Route::get('/books/{id}', [App\Http\Controllers\PublicBookController::class, 'show']);
    Route::post('/attendance', [App\Http\Controllers\AttendanceController::class, 'logAttendance']);
});

/*
|--------------------------------------------------------------------------
| Protected Routes (Must have Token)
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth:sanctum']], function () {

    // Auth
    Route::get('/transactions', [App\Http\Controllers\TransactionController::class, 'index']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Settings Management (Admin)
    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'bulkUpdate']);
    Route::put('/settings/{key}', [SettingController::class, 'update']);
    Route::post('/settings/reset', [SettingController::class, 'reset']);

    // Book Management
    Route::post('/books/title', [BookController::class, 'storeTitle']);
    Route::post('/books/asset', [BookController::class, 'storeAsset']);
    Route::get('/books/next-accession', [BookController::class, 'getNextAccession']);
    Route::get('/books/random-barcode', [BookController::class, 'generateRandomBarcode']);
    Route::get('/books/lost', [BookController::class, 'getLostBooks']); // NEW
    Route::post('/books/assets/{id}/restore', [BookController::class, 'restoreBook']); // NEW

    // Circulation (The New Stuff)
    Route::post('/borrow', [TransactionController::class, 'borrow']);
    Route::post('/return', [TransactionController::class, 'returnBook']);
    Route::post('/transactions/lost', [TransactionController::class, 'markAsLost']); // NEW
    Route::get('/history', [TransactionController::class, 'history']);

    // Payment Management (NEW)
    Route::post('/transactions/{id}/pay', [TransactionController::class, 'markAsPaid']);
    Route::post('/transactions/{id}/waive', [TransactionController::class, 'waiveFine']);
    Route::post('/transactions/{id}/unpaid', [TransactionController::class, 'markAsUnpaid']); // NEW
    Route::get('/students/{id}/fines', [TransactionController::class, 'getStudentFines']); // NEW

    // Student Management
    Route::get('/students', [App\Http\Controllers\StudentController::class, 'index']);
    Route::post('/students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::put('/students/{id}', [App\Http\Controllers\StudentController::class, 'update']);
    Route::delete('/students/{id}', [App\Http\Controllers\StudentController::class, 'destroy']);
    Route::post('/students/batch', [App\Http\Controllers\StudentController::class, 'batchStore']);
    Route::get('/students/{id}/history', [App\Http\Controllers\StudentController::class, 'history']);

    // Gamification: Leaderboard & Achievements
    Route::get('/students/leaderboard', [App\Http\Controllers\StudentController::class, 'leaderboard']);
    Route::get('/students/{id}/achievements', [App\Http\Controllers\StudentController::class, 'achievements']);

    // Dashboard Stats
    Route::get('/dashboard/stats', [BookController::class, 'dashboardStats']);
    Route::get('/dashboard/books', [BookController::class, 'getDashboardBooks']);
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance', [AttendanceController::class, 'index']);
    // Analytics
    Route::get('/analytics/trends', [App\Http\Controllers\AnalyticsController::class, 'monthlyTrends']);
    Route::get('/analytics/categories', [App\Http\Controllers\AnalyticsController::class, 'categoryPopularity']);

    // Book CRUD (Update & Delete)
    Route::put('/books/{id}', [BookController::class, 'update']);
    Route::delete('/books/{id}', [BookController::class, 'destroy']);

    // Notifications
    Route::get('/notifications', [App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [App\Http\Controllers\NotificationController::class, 'markAllRead']);

    // Reports (NEW)
    Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
    Route::get('/reports/top-students', [ReportController::class, 'topStudents']);
    Route::get('/reports/penalties', [ReportController::class, 'penalties']);
    Route::get('/reports/department', [ReportController::class, 'departmentStats']);
    Route::get('/reports/demographics', [ReportController::class, 'demographics']);
    Route::get('/reports/export/{type}', [ReportController::class, 'exportCsv']);

    // User Management (Admin Only)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/check-unique', [UserController::class, 'checkUnique']);

    // Attendance Logs (Admin)
    Route::get('/attendance', [App\Http\Controllers\AttendanceController::class, 'index']);
    Route::get('/attendance/today', [App\Http\Controllers\AttendanceController::class, 'today']);
});