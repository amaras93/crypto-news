<?php

namespace App\Models;

use App\CandidateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['headline', 'original_url', 'canonical_url', 'url_hash', 'headline_hash', 'content_fingerprint', 'language', 'summary', 'published_at', 'discovered_at', 'topic', 'category_id', 'classification', 'duplicate_of_id', 'duplicate_reasons'])]
class NewsCandidate extends Model
{
    public function aiRuns(): HasMany
    {
        return $this->hasMany(AiGenerationRun::class);
    }

    use HasFactory;

    protected function casts(): array
    {
        return ['is_simulated' => 'boolean', 'status' => CandidateStatus::class, 'classification' => 'array', 'duplicate_reasons' => 'array', 'published_at' => 'immutable_datetime', 'discovered_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function references(): HasMany
    {
        return $this->hasMany(CandidateSourceReference::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CandidateReview::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
