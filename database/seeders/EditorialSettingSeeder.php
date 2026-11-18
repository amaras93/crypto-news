<?php

namespace Database\Seeders;

use App\Models\EditorialSetting;
use Illuminate\Database\Seeder;

class EditorialSettingSeeder extends Seeder
{
    public function run(): void
    {
        EditorialSetting::firstOrCreate(['id' => 1]);
    }
}
