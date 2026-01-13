<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class StudentSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'name' => 'John Student',
            'email' => 'student@school.edu',
            'password' => Hash::make('password123'),
            'role' => 'student',
            'status' => 'active',
            'student_id' => '2025-1001', // We will use this ID to borrow books
        ]);
    }
}