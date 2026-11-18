<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class NewsletterCampaign extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['article_ids' => 'array', 'translation_hashes' => 'array', 'period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'queued_at' => 'immutable_datetime'];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
