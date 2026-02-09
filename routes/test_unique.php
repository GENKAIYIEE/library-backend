<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Route::get('/test-unique-check', function () {
    // 1. Setup: Ensure clean state for test email
    $email = 'collision_test@example.com';
    $user = User::withTrashed()->where('email', $email)->first();
    if ($user) {
        $user->forceDelete();
    }

    // 2. Create an active user (Student)
    $activeUser = User::create([
        'name' => 'Active Student',
        'email' => $email,
        'username' => 'activestudent',
        'role' => 'student',
        'password' => bcrypt('password'),
        'student_id' => 'ST-001-' . rand(1000, 9999)
    ]);

    // 3. Check Unique (simulating Controller method)
    // Should return true (exists)
    $existsActive = User::where('email', $email)->exists();

    // 4. Soft delete the user
    $activeUser->delete();

    // 5. Check Unique again (simulating Controller method)
    // Eloquent excludes soft deleted by default -> Should return false (does not exist)
    $existsSoftDeleted = User::where('email', $email)->exists();

    // 6. Check Confirmation with withTrashed
    $existsWithTrashed = User::withTrashed()->where('email', $email)->exists();

    // 7. Check Validator Rule (used in store method)
    // 'unique:users,email' -> Does it check soft deleted?
    $validatorC = Validator::make(['email' => $email], [
        'email' => 'unique:users,email'
    ]);
    $validatorFails = $validatorC->fails();

    // 8. Check Validator Rule with Ignore Soft Delete
    // 'unique:users,email,NULL,id,deleted_at,NULL'
    $validatorIgnoreSoft = Validator::make(['email' => $email], [
        'email' => Rule::unique('users')->whereNull('deleted_at')
    ]);
    $validatorIgnoreSoftFails = $validatorIgnoreSoft->fails();

    return [
        'active_user_exists_in_eloquent' => $existsActive, // Expect true
        'soft_deleted_user_exists_in_eloquent' => $existsSoftDeleted, // Expect false
        'soft_deleted_user_exists_with_trashed' => $existsWithTrashed, // Expect true
        'default_unique_validation_fails_on_soft_deleted' => $validatorFails, // Expect true (it sees the record)
        'unique_validation_ignoring_soft_delete_fails' => $validatorIgnoreSoftFails // Expect false (should pass)
    ];
});
