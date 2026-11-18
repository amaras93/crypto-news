<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'enabled', 'max_candidates_per_run', 'max_sources_per_run', 'default_polling_minutes', 'updated_by'])]
class DiscoverySetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
