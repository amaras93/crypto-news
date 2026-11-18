<?php

namespace Database\Seeders;

use App\Models\AiPipelineSetting;
use Illuminate\Database\Seeder;

class AiPipelineSettingSeeder extends Seeder
{
    /** Initialize safe controls without changing an existing operator configuration. */
    public function run(): void
    {
        AiPipelineSetting::firstOrCreate(['id' => 1], ['emergency_stop' => true]);
    }
}
