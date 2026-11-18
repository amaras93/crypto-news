<?php

namespace Database\Factories;

use App\Models\NewsletterSubscriber;
use App\Support\NewsletterSubscriptions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NewsletterSubscriber> */
class NewsletterSubscriberFactory extends Factory
{
    public function definition(): array
    {
        return ['public_id' => Str::uuid()->toString(), 'email' => fake()->unique()->safeEmail(),
            'email_hash' => fn (array $attributes): string => app(NewsletterSubscriptions::class)->emailHash($attributes['email']),
            'locale' => 'en', 'frequency' => 'daily', 'consent_at' => now(), 'consent_version' => config('engagement.consent_version'),
            'unsubscribe_secret' => Str::random(64),
            'unsubscribe_token_hash' => fn (array $attributes): string => hash('sha256', $attributes['unsubscribe_secret']),
            'version' => 1, 'confirmation_generation' => 0];
    }
}
