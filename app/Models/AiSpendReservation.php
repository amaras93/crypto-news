<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['request_key', 'news_candidate_id', 'user_id', 'day_key', 'month_key', 'provider', 'model', 'estimated_microusd'])]
class AiSpendReservation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['counts_article' => 'boolean', 'settled_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(NewsCandidate::class, 'news_candidate_id');
    }
}
