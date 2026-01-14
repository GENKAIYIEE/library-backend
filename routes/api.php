<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\TransactionController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::get('/books', [BookController::class, 'index']);
Route::get('/books/search/{keyword}', [BookController::class, 'search']);

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

    // Book Management
    Route::post('/books/title', [BookController::class, 'storeTitle']);
    Route::post('/books/asset', [BookController::class, 'storeAsset']);

    // Circulation (The New Stuff)
    Route::post('/borrow', [TransactionController::class, 'borrow']);
    Route::post('/return', [TransactionController::class, 'returnBook']);
    Route::get('/history', [TransactionController::class, 'history']);
    // Student Management
    Route::get('/students', [App\Http\Controllers\StudentController::class, 'index']);
    Route::post('/students', [App\Http\Controllers\StudentController::class, 'store']);
    Route::delete('/students/{id}', [App\Http\Controllers\StudentController::class, 'destroy']);

    // Dashboard Stats
    Route::get('/dashboard/stats', [BookController::class, 'dashboardStats']);

    // Book CRUD (Update & Delete)
    Route::put('/books/{id}', [BookController::class, 'update']);
    Route::delete('/books/{id}', [BookController::class, 'destroy']);
});