<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['asset', 'currency', 'unit', 'target_price', 'direction', 'enabled', 'in_app', 'email'])]
class PriceAlert extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['target_price' => 'string', 'last_price' => 'string', 'enabled' => 'boolean', 'in_app' => 'boolean', 'email' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'last_triggered_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
