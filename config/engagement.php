<?php

return [
    'price_alerts_enabled' => (bool) env('PRICE_ALERTS_ENABLED', false),
    'newsletters_enabled' => (bool) env('NEWSLETTERS_ENABLED', false),
    'real_email_enabled' => (bool) env('ENGAGEMENT_REAL_EMAIL_ENABLED', false),
    'emails_per_minute' => min(600, max(1, (int) env('ENGAGEMENT_EMAILS_PER_MINUTE', 60))),
    'batch_size' => min(500, max(1, (int) env('ENGAGEMENT_BATCH_SIZE', 100))),
    'watchlist_limit' => 30,
    'active_alert_limit' => 20,
    'confirmation_hours' => 48,
    'confirmation_cooldown_minutes' => 30,
    'consent_version' => 'newsletter-v1',
    'max_digest_stories' => 6,
];
