<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([])]
#[Hidden(['provider_review_notes', 'seller_review_notes'])]
class AdvertisingSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'direct_enabled' => 'boolean', 'adsense_enabled' => 'boolean', 'adsense_reviewed' => 'boolean',
            'ads_txt_verified' => 'boolean', 'provider_review_notes' => 'encrypted', 'seller_review_notes' => 'encrypted'];
    }
}
