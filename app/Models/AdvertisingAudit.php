<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([])]
#[Hidden(['details'])]
class AdvertisingAudit extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }
}
