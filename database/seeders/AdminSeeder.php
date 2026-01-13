<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@school.edu',
            'password' => Hash::make('password123'), // Encrypted password
            'role' => 'admin',
            'status' => 'active',
            'student_id' => null, // Admins don't need a student ID
        ]);
    }
}