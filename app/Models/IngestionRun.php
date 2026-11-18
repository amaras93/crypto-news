<?php

namespace App\Models;

use App\IngestionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['news_source_id', 'requested_by', 'token', 'source_version', 'item_limit', 'queued_at'])]
class IngestionRun extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => IngestionStatus::class, 'item_errors' => 'array', 'queued_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'retry_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'news_source_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
