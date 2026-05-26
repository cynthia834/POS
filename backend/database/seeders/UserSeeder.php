<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin account
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@pos.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Cashier account
        User::create([
            'name' => 'Cashier User 1',
            'email' => 'cashier1@pos.com',
            'password' => Hash::make('password'),
            'role' => 'cashier',
        ]);
    }
}
