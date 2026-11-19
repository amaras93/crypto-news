<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'automatic_publishing', 'enabled_category_ids', 'candidate_processing_enabled', 'generation_enabled', 'translation_enabled', 'scheduled_generation_enabled', 'emergency_stop', 'provider', 'model', 'daily_limit_microusd', 'monthly_limit_microusd', 'max_articles_per_day', 'max_generation_attempts_per_day', 'max_concurrent_jobs', 'max_translation_languages', 'author_id', 'updated_by'])]
class AiPipelineSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['automatic_publishing' => 'boolean', 'enabled_category_ids' => 'array', 'candidate_processing_enabled' => 'boolean', 'generation_enabled' => 'boolean', 'translation_enabled' => 'boolean', 'scheduled_generation_enabled' => 'boolean', 'emergency_stop' => 'boolean'];
    }
}
