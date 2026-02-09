<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Http\Request;

Route::get('/debug-unique-failure', function (Request $request) {
    // 1. Simulate the input that would cause a "false positive"
    // The user says "different emails" still fail. Let's try a properly random one.
    $field = 'email';
    $value = 'definitely.does.not.exist.' . rand(1000, 9999) . '@example.com';

    // 2. Build the query exactly as the Controller does
    $query = User::where($field, $value)->whereNull('deleted_at');

    // 3. Get SQL and Bindings
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    // 4. Run 'exists()'
    $exists = $query->exists();

    // 5. Check if there are ANY users at all
    $totalUsers = User::count();
    $totalSoftDeleted = User::onlyTrashed()->count();

    // 6. Check if there are any users with NULL email?
    $nullEmailCount = User::whereNull('email')->count();

    return [
        'test_email' => $value,
        'sql' => $sql,
        'bindings' => $bindings,
        'exists_result' => $exists, // Should be false
        'logic_check' => $exists ? 'FAIL: Found phantom user' : 'PASS: Logic works for new email',
        'db_stats' => [
            'total_users' => $totalUsers,
            'soft_deleted' => $totalSoftDeleted,
            'null_emails' => $nullEmailCount,
        ],
        'first_5_users' => User::take(5)->get(['id', 'email', 'deleted_at']),
        'trashed_users' => User::onlyTrashed()->take(5)->get(['id', 'email', 'deleted_at'])
    ];
});
