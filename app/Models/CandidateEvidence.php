<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['claim_key', 'news_candidate_id', 'claim', 'reference_ids', 'entities', 'figures', 'material', 'review_notes', 'verified_by', 'verified_at'])]
class CandidateEvidence extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['reference_ids' => 'array', 'entities' => 'array', 'figures' => 'array', 'material' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(NewsCandidate::class, 'news_candidate_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
