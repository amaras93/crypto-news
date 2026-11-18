<?php

namespace App\Filament;

use App\Support\AdvertisingManager;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;

class AdvertisingForms
{
    public static function review(): array
    {
        $labels = ['rights_confirmed' => 'Creative, brand and content rights verified',
            'claims_truthful' => 'All claims and the complete image/content manually checked for accuracy',
            'no_guaranteed_returns' => 'No guaranteed profits, misleading risk-free claims or deceptive offers',
            'all_served_territories_reviewed' => 'Promotion rules reviewed for every territory served; language targeting is not a country restriction',
            'platform_policies_reviewed' => 'Applicable platform policies and required financial disclosures reviewed'];
        $fields = [];
        foreach (AdvertisingManager::reviewFields() as $field) {
            $fields[] = Checkbox::make($field)->label($labels[$field])->rules(['accepted'])->required();
        }
        $fields[] = Textarea::make('notes')->required()->minLength(50)->maxLength(4000)->rows(5)
            ->helperText('Record actual evidence, sensitive-category review and jurisdiction limitations. This is a human approval record, not automated legal approval.');

        return $fields;
    }
}
