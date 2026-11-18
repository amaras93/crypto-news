<?php

namespace Database\Factories;

use App\Models\EditorialSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EditorialSetting> */
class EditorialSettingFactory extends Factory
{
    public function definition(): array
    {
        return ['default_author_id' => null, 'default_category_id' => null];
    }
}
