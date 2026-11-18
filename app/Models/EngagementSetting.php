<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'price_alerts_enabled', 'newsletters_enabled', 'real_email_enabled', 'daily_at', 'weekly_day', 'weekly_at', 'emails_per_minute', 'batch_size', 'updated_by'])]
class EngagementSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_alerts_enabled' => 'boolean', 'newsletters_enabled' => 'boolean', 'real_email_enabled' => 'boolean'];
    }
}
