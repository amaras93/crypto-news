<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetReaderPassword;
use App\Notifications\VerifyReaderEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReaderAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public static function locales(): array
    {
        return array_map(fn (string $locale): array => [$locale], ['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh']);
    }

    private function registration(): array
    {
        return ['name' => 'Real Reader', 'email' => 'Reader@example.test', 'password' => 'Secure-Reader123!', 'password_confirmation' => 'Secure-Reader123!', 'website' => ''];
    }

    #[DataProvider('locales')]
    public function test_registration_and_private_authentication_pages_work_in_every_locale(string $locale): void
    {
        Notification::fake();
        foreach (['login', 'register', 'forgot-password'] as $page) {
            $this->get("/{$locale}/{$page}")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertSee('noindex,nofollow,noarchive', false)->assertDontSee('rel="canonical"', false)->assertDontSee('community.login');
        }
        $this->post("/{$locale}/register", $this->registration())->assertRedirect("/{$locale}/verify-email");
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->is_editor);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('reader@example.test', $user->email);
        $this->assertSame($locale, $user->preferredLocale());
        $this->assertTrue(Hash::check('Secure-Reader123!', $user->password));
        Notification::assertSentTo($user, VerifyReaderEmail::class);
        $this->get('/admin')->assertForbidden();
        $this->get("/{$locale}/account")->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_registration_rejects_role_escalation_and_honeypots(): void
    {
        foreach (['is_admin', 'is_editor', 'role'] as $field) {
            $this->post('/en/register', $this->registration() + [$field => 'administrator'])->assertSessionHasErrors('community');
        }
        $this->post('/en/register', array_replace($this->registration(), ['website' => 'https://spam.test']))->assertSessionHasErrors();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_weak_passwords_and_case_duplicate_emails(): void
    {
        User::factory()->create(['email' => 'reader@example.test']);
        $this->post('/en/register', array_replace($this->registration(), ['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.test']);
        $this->withSession(['sentinel' => 'before']);
        $session = session()->getId();
        $this->post('/ru/login', ['email' => 'READER@example.test', 'password' => 'password', 'remember' => '1'])->assertRedirect('/ru/account');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($session, session()->getId());
        $this->post('/ru/logout')->assertRedirect('/ru');
        $this->assertGuest();
        $this->assertNull(session('sentinel'));
    }

    public function test_failed_logins_are_generic_and_rate_limited(): void
    {
        User::factory()->create(['email' => 'reader@example.test']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/en/login', ['email' => 'reader@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/en/login', ['email' => 'reader@example.test', 'password' => 'password'])->assertSessionHasErrors('community');
        $this->assertGuest();
    }

    public function test_signed_verification_requires_the_matching_session_hash_and_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['locale' => 'en', 'id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
        $this->get($url)->assertRedirect('/en/login');
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($user)->get($url.'&signature=bad')->assertForbidden();
        $this->get($url)->assertRedirect('/en/account');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get($url)->assertRedirect('/en/account');
    }

    public function test_expired_verification_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['locale' => 'en', 'id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_password_is_generic_and_reset_tokens_are_single_use(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reader@example.test']);
        $this->post('/en/forgot-password', ['email' => $user->email])->assertSessionHas('community_status', __('community.reset_sent'));
        Notification::assertSentTo($user, ResetReaderPassword::class);
        $this->post('/en/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('community_status', __('community.reset_sent'));
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'New-Password123!', 'password_confirmation' => 'New-Password123!'];
        $this->post('/en/reset-password', array_replace($payload, ['token' => 'bad']))->assertSessionHasErrors();
        $this->post('/en/reset-password', $payload)->assertRedirect('/en/login');
        $this->assertTrue(Hash::check('New-Password123!', $user->fresh()->password));
        $this->post('/en/reset-password', $payload)->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_verification_and_password_notifications_are_queued_and_use_trusted_localized_urls(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create(['preferred_locale' => 'ja']);
        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('secret-test-token');
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $this->app->setLocale('ja');
        $verify = (new VerifyReaderEmail)->toMail($user);
        $reset = (new ResetReaderPassword('test-token'))->toMail($user);
        $this->assertStringStartsWith(config('app.url').'/ja/verify-email/', $verify->actionUrl);
        $this->assertStringStartsWith(config('app.url').'/ja/reset-password/test-token', $reset->actionUrl);
        $this->assertSame(__('community.verify_subject'), $verify->subject);
        $this->assertInstanceOf(ShouldBeEncrypted::class, new ResetReaderPassword('test-token'));
    }

    public function test_resending_verification_is_throttled_and_admin_login_is_preserved(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/en/verify-email')->assertSessionHasNoErrors();
        }
        $this->post('/en/verify-email')->assertSessionHasErrors('community');
        $this->actingAs(User::factory()->admin()->create())->get('/admin')->assertOk();
    }

    public function test_public_registration_requires_csrf_tokens_outside_the_test_bypass(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/en/register', $this->registration())->assertStatus(419);
        $this->post('/en/login', ['email' => 'reader@example.test', 'password' => 'password'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }
}
