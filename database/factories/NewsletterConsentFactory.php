<?php

namespace Database\Factories;

use App\Models\NewsletterConsent;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NewsletterConsent> */
class NewsletterConsentFactory extends Factory
{
    public function definition(): array
    {
        return ['newsletter_subscriber_id' => NewsletterSubscriber::factory(),
            'email_hash' => fn (array $attributes): string => NewsletterSubscriber::findOrFail($attributes['newsletter_subscriber_id'])->email_hash,
            'action' => 'requested', 'locale' => 'en', 'frequency' => 'daily', 'consent_version' => config('engagement.consent_version')];
    }
}
