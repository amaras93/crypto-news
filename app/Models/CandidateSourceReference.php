<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['news_candidate_id', 'news_source_id', 'ingestion_run_id', 'external_id', 'external_hash', 'original_url', 'headline', 'language', 'source_published_at', 'first_seen_at', 'last_seen_at'])]
class CandidateSourceReference extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['source_published_at' => 'immutable_datetime', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(NewsCandidate::class, 'news_candidate_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(IngestionRun::class, 'ingestion_run_id');
    }
}
