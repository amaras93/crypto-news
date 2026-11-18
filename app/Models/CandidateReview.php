<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['news_candidate_id', 'user_id', 'previous_status', 'new_status', 'notes'])]
class CandidateReview extends Model
{
    use HasFactory;

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(NewsCandidate::class, 'news_candidate_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
