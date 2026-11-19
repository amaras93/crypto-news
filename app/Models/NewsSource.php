<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'website', 'feed_url', 'feed_hash', 'source_type', 'language', 'category_id', 'topic', 'priority', 'enabled', 'summary_permitted', 'licensing_notes', 'polling_minutes', 'item_limit'])]
class NewsSource extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['primary_material_permitted' => 'boolean', 'ai_usage_allowed' => 'boolean', 'is_primary' => 'boolean', 'is_simulated' => 'boolean', 'enabled' => 'boolean', 'summary_permitted' => 'boolean', 'approved_at' => 'immutable_datetime', 'last_fetched_at' => 'immutable_datetime', 'last_success_at' => 'immutable_datetime', 'next_poll_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IngestionRun::class);
    }

    public function references(): HasMany
    {
        return $this->hasMany(CandidateSourceReference::class);
    }
}
