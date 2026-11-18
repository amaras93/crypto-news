<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([])]
#[Hidden(['contract_amount', 'currency', 'review_notes', 'review_checks', 'approval_hash'])]
class AdCampaign extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['languages' => 'array', 'alt_text' => 'array', 'review_checks' => 'array', 'contract_amount' => 'encrypted',
            'review_notes' => 'encrypted', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function placements(): BelongsToMany
    {
        return $this->belongsToMany(AdPlacement::class);
    }
}
