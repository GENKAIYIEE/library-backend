<?php

use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Mail\UserCredentialsMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

Route::get('/test-email-sending', function () {
    $user = new User([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'username' => 'testuser',
    ]);
    $password = 'secret123';

    Mail::to($user->email)->send(new UserCredentialsMail($user, $password));

    return 'Email sent';
});
