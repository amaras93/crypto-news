<?php

namespace Database\Factories;

use App\Models\AdvertisingSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvertisingSetting> */
class AdvertisingSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => 1, 'enabled' => false, 'direct_enabled' => false, 'adsense_enabled' => false, 'adsense_reviewed' => false, 'ads_txt_verified' => false, 'revision' => 1];
    }
}
