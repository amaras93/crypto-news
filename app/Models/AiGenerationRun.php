<?php

namespace App\Models;

use App\AiRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['request_key', 'work_hash', 'active_key', 'news_candidate_id', 'requested_by', 'billing_user_id', 'author_id', 'task', 'locale', 'section_key', 'provider', 'model', 'is_simulated', 'evidence_digest', 'evidence_snapshot', 'english_digest', 'target_digest', 'confirm_human_overwrite', 'editor_regeneration_allowed'])]
class AiGenerationRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => AiRunStatus::class, 'is_simulated' => 'boolean', 'editor_regeneration_allowed' => 'boolean', 'confirm_human_overwrite' => 'boolean', 'evidence_snapshot' => 'encrypted:array', 'input_payload' => 'encrypted:array', 'output_document' => 'encrypted:array', 'quality_report' => 'array', 'pricing_snapshot' => 'array', 'simulated_usage' => 'array', 'request_sent_at' => 'immutable_datetime', 'retry_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'section_applied_at' => 'immutable_datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(NewsCandidate::class, 'news_candidate_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function billingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_user_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(AiSpendReservation::class, 'reservation_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }
}
