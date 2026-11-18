<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class PriceAlertEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['target_price' => 'string', 'previous_price' => 'string', 'observed_price' => 'string'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(PriceAlert::class, 'price_alert_id');
    }
}
