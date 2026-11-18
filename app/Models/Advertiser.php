<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
#[Hidden(['business_name', 'contact_information', 'internal_notes'])]
class Advertiser extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['contact_information' => 'encrypted', 'internal_notes' => 'encrypted'];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(AdCampaign::class);
    }
}
