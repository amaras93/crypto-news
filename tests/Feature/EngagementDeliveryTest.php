<?php

namespace Tests\Feature;

use App\Jobs\DeliverEngagementMessage;
use App\Mail\EngagementMessage;
use App\Models\EngagementDelivery;
use App\Models\EngagementSetting;
use App\Models\User;
use App\Support\EngagementDeliveries;
use App\Support\NewsletterSubscriptions;
use App\Support\PriceAlerts;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Tests\EngagementTestCase;

class EngagementDeliveryTest extends EngagementTestCase
{
    private function confirmation(string $locale = 'en'): EngagementDelivery
    {
        app(NewsletterSubscriptions::class)->request(['email' => 'subscriber@example.com', 'locale' => $locale, 'frequency' => 'daily', 'consent' => true]);

        return EngagementDelivery::sole();
    }

    public function test_log_mail_is_not_reported_as_smtp_acceptance_and_token_payload_is_redacted(): void
    {
        config(['mail.default' => 'log']);
        $delivery = $this->confirmation('ja');
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('local', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->provider_message_id);
        $this->assertNull($delivery->fresh()->payload);
        Mail::assertSent(EngagementMessage::class, fn (EngagementMessage $message): bool => $message->locale === 'ja');
        app(EngagementDeliveries::class)->process($delivery->id);
        Mail::assertSentCount(1);
    }

    public function test_smtp_requires_both_explicit_controls_and_never_uses_a_fallback_mailer(): void
    {
        config(['mail.default' => 'smtp']);
        $delivery = $this->confirmation();
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('skipped', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_null_smtp_confirmation_is_uncertain_and_does_not_retry(): void
    {
        config(['mail.default' => 'smtp', 'engagement.real_email_enabled' => true]);
        EngagementSetting::updateOrCreate(['id' => 1], ['real_email_enabled' => true]);
        $delivery = $this->confirmation();
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        app(EngagementDeliveries::class)->process($delivery->id);
        Mail::assertSentCount(1);
    }

    public function test_database_notifications_are_queued_stored_once_and_private(): void
    {
        $this->liveAlerts();
        $this->alert();
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(110));
        app(PriceAlerts::class)->evaluate();
        $delivery = EngagementDelivery::sole();
        $this->assertSame('queued', $delivery->status);
        $this->assertDatabaseCount('notifications', 0);
        app(EngagementDeliveries::class)->process($delivery->id);
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('stored', $delivery->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);
        $this->get('/en/account/notifications')->assertOk()->assertSee(__('engagement.unread'));
        $this->patch('/en/account/notifications/'.$delivery->id)->assertRedirect();
        $this->assertNotNull($this->reader->notifications()->sole()->read_at);
        Mail::assertNothingSent();
    }

    public function test_a_crashed_sending_job_is_uncertain_and_never_automatically_resubmitted(): void
    {
        $delivery = $this->confirmation();
        $delivery->forceFill(['status' => 'sending', 'sending_at' => now()->subMinutes(6), 'attempts' => 1])->save();
        app(EngagementDeliveries::class)->dispatchPending();
        $this->assertSame('uncertain', $delivery->fresh()->status);
        (new DeliverEngagementMessage($delivery->id))->handle(app(EngagementDeliveries::class));
        Mail::assertNothingSent();
    }

    private function crossing(bool $email = false): void
    {
        $this->reader->forceFill(['price_alert_email' => $email])->save();
        $this->liveAlerts();
        $this->alert(['email' => $email]);
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(110));
        app(PriceAlerts::class)->evaluate();
    }

    public function test_changed_preferences_and_unverified_email_cancel_queued_alert_emails(): void
    {
        $this->crossing(true);
        $mail = EngagementDelivery::where('channel', 'mail')->sole();
        $this->reader->forceFill(['price_alert_email' => false])->save();
        app(EngagementDeliveries::class)->process($mail->id);
        $this->assertSame('skipped', $mail->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_unverified_readers_never_queue_price_alert_emails(): void
    {
        $this->reader->forceFill(['email_verified_at' => null])->save();
        $this->crossing(true);
        $this->assertSame(0, EngagementDelivery::where('channel', 'mail')->count());
        $this->assertSame(1, EngagementDelivery::where('channel', 'database')->count());
    }

    public function test_return_to_demo_or_stale_cache_cancels_queued_alert_deliveries(): void
    {
        $this->crossing(true);
        $database = EngagementDelivery::where('channel', 'database')->sole();
        $mail = EngagementDelivery::where('channel', 'mail')->sole();
        $this->snapshot($this->quote(110, status: 'stale'));
        app(EngagementDeliveries::class)->process($database->id);
        config(['markets.mode' => 'demo']);
        app(EngagementDeliveries::class)->process($mail->id);
        $this->assertSame('skipped', $database->fresh()->status);
        $this->assertSame('skipped', $mail->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingSent();
    }

    public function test_other_users_cannot_read_or_mark_a_private_notification(): void
    {
        $this->crossing();
        $delivery = EngagementDelivery::sole();
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->actingAs(User::factory()->create());
        $this->patch('/en/account/notifications/'.$delivery->id)->assertNotFound();
        $this->get('/en/account/notifications')->assertOk()->assertSee(__('engagement.no_notifications'));
        $this->assertNull($this->reader->notifications()->sole()->read_at);
    }

    public function test_shared_email_rate_limit_defers_work_without_a_send_attempt(): void
    {
        config(['mail.default' => 'log', 'engagement.emails_per_minute' => 1]);
        $first = $this->confirmation();
        app(NewsletterSubscriptions::class)->request(['email' => 'second@example.com', 'locale' => 'en', 'frequency' => 'daily', 'consent' => true]);
        $second = EngagementDelivery::whereKeyNot($first->id)->sole();
        app(EngagementDeliveries::class)->process($first->id);
        app(EngagementDeliveries::class)->process($second->id);
        $this->assertSame('queued', $second->fresh()->status);
        $this->assertSame(0, $second->fresh()->attempts);
        $this->assertNull($second->fresh()->queued_at);
        Mail::assertSentCount(1);
        $this->travel(2)->minutes();
        app(EngagementDeliveries::class)->process($second->id);
        $this->assertSame('local', $second->fresh()->status);
        Mail::assertSentCount(2);
    }

    public function test_known_unsent_work_can_be_recovered_but_uncertain_attempts_cannot_be_retried(): void
    {
        $delivery = $this->confirmation();
        (new DeliverEngagementMessage($delivery->id))->failed(new \RuntimeException('Private exception detail'));
        $this->assertSame('failed', $delivery->fresh()->status);
        $admin = User::factory()->admin()->create();
        app(EngagementDeliveries::class)->retryUnsent($admin, $delivery);
        $this->assertSame('queued', $delivery->fresh()->status);
        $delivery->forceFill(['status' => 'uncertain', 'attempts' => 1])->save();
        $this->expectException(HttpException::class);
        app(EngagementDeliveries::class)->retryUnsent($admin, $delivery);
    }

    public function test_uncertain_mail_requires_audited_manual_resolution_without_resending(): void
    {
        $delivery = $this->confirmation();
        $delivery->forceFill(['status' => 'uncertain', 'attempts' => 1, 'transport' => 'smtp'])->save();
        app(EngagementDeliveries::class)->resolveUnknown(User::factory()->admin()->create(), $delivery, true,
            'verified-message-id@example.test', 'SMTP transport logs checked by an authorized operator.');
        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame('verified-message-id@example.test', $delivery->fresh()->provider_message_id);
        $this->assertArrayHasKey('operator_resolution', $delivery->fresh()->payload);
        $this->assertArrayNotHasKey('confirmation_url', $delivery->fresh()->payload);
        app(EngagementDeliveries::class)->process($delivery->id);
        Mail::assertNothingSent();
    }

    public function test_only_an_actual_smtp_acceptance_is_recorded_as_accepted(): void
    {
        config(['mail.default' => 'smtp', 'engagement.real_email_enabled' => true]);
        EngagementSetting::updateOrCreate(['id' => 1], ['real_email_enabled' => true]);
        $delivery = $this->confirmation();
        $message = (new Email)->from('sender@example.test')->to('recipient@example.test')->subject('Test')->text('Transport acceptance fixture');
        $ack = new SentMessage(new \Symfony\Component\Mailer\SentMessage($message, Envelope::create($message)));
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->with(\Mockery::type(EngagementMessage::class))->andReturn($ack);
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
        app(EngagementDeliveries::class)->process($delivery->id);
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('accepted', $delivery->fresh()->status);
        $this->assertSame($ack->getMessageId(), $delivery->fresh()->provider_message_id);
    }

    public function test_transport_exceptions_are_private_uncertain_and_never_blindly_retried(): void
    {
        config(['mail.default' => 'smtp', 'engagement.real_email_enabled' => true]);
        EngagementSetting::updateOrCreate(['id' => 1], ['real_email_enabled' => true]);
        $delivery = $this->confirmation();
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('Secret SMTP user and credentials'));
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
        app(EngagementDeliveries::class)->process($delivery->id);
        app(EngagementDeliveries::class)->process($delivery->id);
        $this->assertSame('uncertain', $delivery->fresh()->status);
        $this->assertSame('mail_outcome_unknown', $delivery->fresh()->error_code);
        $this->assertStringNotContainsString('credentials', $delivery->fresh()->toJson());
    }
}
