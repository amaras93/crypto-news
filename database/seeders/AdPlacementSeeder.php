<?php

namespace Database\Seeders;

use App\Models\AdPlacement;
use Illuminate\Database\Seeder;

class AdPlacementSeeder extends Seeder
{
    /** Create only missing disabled system positions; preserve existing operator settings. */
    public function run(): void
    {
        foreach (config('advertising.placements') as $key => $definition) {
            if (AdPlacement::where('key', $key)->exists()) {
                continue;
            }
            $placement = new AdPlacement;
            $placement->forceFill(['key' => $key, 'name' => $definition['name'], 'enabled' => false, 'provider' => 'direct'])->save();
        }
    }
}
