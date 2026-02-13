<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        \App\Models\User::create([
            'name' => 'System Admin',
            'email' => 'admin@library.com',
            'password' => bcrypt('password'), // standard default password
            'role' => 'admin',
            'status' => 'active',
            'username' => 'admin',
            'permissions' => 'full_access'
        ]);

        $this->call([
            BookSeeder::class,
        ]);
    }
}
