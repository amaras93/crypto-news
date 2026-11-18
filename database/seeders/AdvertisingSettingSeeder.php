<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdvertisingSettingSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('advertising_settings')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
