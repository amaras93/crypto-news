<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['article_translation_id', 'ai_generation_run_id', 'user_id', 'decision', 'content_digest', 'evidence_digest', 'notes', 'quality_report'])]
class AiEditorialReview extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['quality_report' => 'array'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
