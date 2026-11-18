<?php

namespace Database\Factories;

use App\Models\EngagementDelivery;
use App\Models\NewsletterSubscriber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EngagementDelivery> */
class EngagementDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => Str::uuid()->toString(), 'unique_key' => hash('sha256', fake()->uuid()), 'kind' => 'confirmation', 'channel' => 'mail',
            'status' => 'queued', 'locale' => 'en', 'newsletter_subscriber_id' => NewsletterSubscriber::factory(),
            'available_at' => now(), 'attempts' => 0, 'dispatch_failures' => 0];
    }
}
