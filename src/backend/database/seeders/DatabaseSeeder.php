<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds only the operator accounts. The DVI records themselves come from
     * the dataset via `php artisan dvi:ingest` — they are source evidence, not
     * fixtures, and are deliberately not generated here.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'coordinator@dvi.test'],
            ['name' => 'DVI Coordinator', 'password' => 'password'],
        );
    }
}
