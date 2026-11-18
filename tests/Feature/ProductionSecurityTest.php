<?php

namespace Tests\Feature;

use App\Filament\Pages\SecureLogin;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Models\AdCampaign;
use App\Models\User;
use App\Rules\SafePasswordBytes;
use App\Support\AdvertisingManager;
use App\Support\EditorialUsers;
use App\Support\ReaderAccounts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    public function test_multibyte_password_suffixes_are_not_silently_discarded_during_registration(): void
    {
        $prefix = str_repeat('密', 24);
        $first = $prefix.'Aa1!x';
        $second = $prefix.'Bb2!y';
        $this->assertLessThan(64, mb_strlen($first));
        $this->assertGreaterThan(72, strlen($first));
        $this->assertTrue(Hash::check($second, Hash::make($first)));
        $this->post('/en/register', ['name' => 'Unicode reader', 'email' => 'unicode@example.com', 'password' => $first, 'password_confirmation' => $first])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_null_bytes_are_rejected_as_validation_errors_instead_of_server_errors(): void
    {
        $password = "ValidPassword!2026\0";
        $this->post('/en/register', ['name' => 'Reader', 'email' => 'reader@example.com', 'password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_malformed_login_email_is_a_validation_error(): void
    {
        $this->post('/en/login', ['email' => ['unexpected'], 'password' => 'NormalPassword!2026'])->assertSessionHasErrors('email');
    }

    public function test_valid_multibyte_passwords_can_register_and_authenticate(): void
    {
        $password = str_repeat('密', 10).'Aa1!valid';
        $user = app(ReaderAccounts::class)->register(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => $password, 'password_confirmation' => $password], 'ja');
        $this->assertTrue(Hash::check($password, $user->password));
        $this->post('/ja/login', ['email' => $user->email, 'password' => $password])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('publicLocales')]
    public function test_password_byte_errors_are_localized_without_translation_key_fallbacks(string $locale): void
    {
        app()->setLocale($locale);
        $validator = Validator::make(['password' => str_repeat('密', 25)], ['password' => [new SafePasswordBytes]]);
        $this->assertTrue($validator->fails());
        $this->assertSame(__('community.password_bytes'), $validator->errors()->first('password'));
        $this->assertNotSame('community.password_bytes', $validator->errors()->first('password'));
    }

    public function test_editorial_user_creation_rejects_truncated_passwords(): void
    {
        $admin = User::factory()->admin()->create();
        $this->expectException(ValidationException::class);
        app(EditorialUsers::class)->save($admin, ['name' => 'New editor', 'email' => 'editor@example.test', 'role' => 'editor', 'password' => str_repeat('密', 24).'Aa1!valid']);
    }

    public function test_password_change_and_reset_reject_unsafe_bytes_without_modifying_the_hash(): void
    {
        $reader = User::factory()->create();
        $original = $reader->password;
        $password = str_repeat('密', 24).'Aa1!valid';
        $this->actingAs($reader)->put('/en/account/password', ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        Auth::logout();
        $this->post('/en/reset-password', ['token' => 'invalid', 'email' => $reader->email, 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->assertSame($original, $reader->fresh()->password);
    }

    public function test_filament_login_rejects_legacy_ambiguous_passwords(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $prefix = str_repeat('密', 24);
        $admin = User::factory()->admin()->create(['password' => $prefix.'Aa1!old']);
        Livewire::test(SecureLogin::class)->fillForm(['email' => $admin->email, 'password' => $prefix.'Bb2!other'])->call('authenticate')->assertHasFormErrors(['password']);
        $this->assertGuest();
    }

    public function test_filament_login_rejects_malformed_passwords_without_server_errors(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(SecureLogin::class)->fillForm(['email' => 'admin@example.test', 'password' => ['unexpected']])->call('authenticate')->assertHasFormErrors(['password']);
        $this->assertGuest();
    }

    public function test_malformed_account_fields_do_not_cause_server_errors(): void
    {
        $this->post('/en/register', ['name' => 'Reader', 'email' => ['bad'], 'password' => 'NormalPassword!2026', 'password_confirmation' => 'NormalPassword!2026'])->assertSessionHasErrors('email');
        $user = User::factory()->create();
        $this->actingAs($user)->patch('/en/account', ['name' => 'Reader', 'email' => ['bad'], 'preferred_locale' => 'en', 'reply_notifications' => false, 'current_password' => ['bad']])->assertSessionHasErrors(['email', 'current_password']);
    }

    public function test_security_headers_cover_public_admin_private_and_error_responses(): void
    {
        foreach (['/en', '/admin/login', '/en/login', '/en/missing-page'] as $path) {
            $response = $this->get($path);
            $this->assertContains($response->status(), [200, 404]);
            $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'")
                ->assertHeaderMissing('Strict-Transport-Security');
        }
        $this->get('/en/login')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/en')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_hsts_is_opt_in_and_requires_production_https(): void
    {
        config(['app.url' => 'https://news.example.test', 'security.hsts_enabled' => true]);
        $this->get('https://news.example.test/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->app['env'] = 'production';
        $this->get('http://news.example.test/up')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://news.example.test/up')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        config(['security.hsts_enabled' => false]);
        $this->get('https://news.example.test/up')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_production_rejects_unapproved_hosts_including_health_checks(): void
    {
        config(['app.url' => 'https://news.example.test', 'security.additional_hosts' => ['www.news.example.test']]);
        $this->app['env'] = 'production';
        $this->get('https://news.example.test/up')->assertOk();
        $this->get('https://www.news.example.test/up')->assertOk();
        $this->get('https://spoof.example.test/up')->assertBadRequest();
        $this->get('https://sub.news.example.test/up')->assertBadRequest();
    }

    public function test_only_explicit_proxy_addresses_can_supply_forwarded_scheme_and_host_is_never_trusted(): void
    {
        $middleware = app(TrustConfiguredProxies::class);
        config(['security.trusted_proxies' => ['10.20.0.0/16', '*', '0.0.0.0/0']]);
        foreach (['10.20.1.2' => true, '203.0.113.10' => false] as $address => $secure) {
            $request = Request::create('http://news.example.test/up', server: ['REMOTE_ADDR' => $address, 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'spoof.example.test']);
            $middleware->handle($request, function (Request $request) use ($secure) {
                $this->assertSame($secure, $request->isSecure());
                $this->assertSame('news.example.test', $request->getHost());

                return response('ok');
            });
        }
    }

    public function test_reader_mutations_require_csrf_tokens_outside_the_test_bypass(): void
    {
        $this->app['env'] = 'local';
        $this->post('/en/register', ['name' => 'Reader', 'email' => 'reader@example.test', 'password' => 'NormalPassword!2026', 'password_confirmation' => 'NormalPassword!2026'])->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_advertising_role_revocation_is_rechecked_on_the_next_request_and_before_writes(): void
    {
        $admin = User::factory()->admin()->create();
        $campaign = AdCampaign::factory()->create();
        $this->actingAs($admin)->get('/admin/ad-campaigns')->assertOk();
        User::query()->whereKey($admin->id)->update(['is_admin' => false]);
        $this->get('/admin/ad-campaigns')->assertForbidden();
        $this->expectException(HttpException::class);
        app(AdvertisingManager::class)->pause($admin, $campaign);
    }

    public static function publicLocales(): array
    {
        return [['en'], ['ru'], ['fr'], ['de'], ['es'], ['it'], ['ja'], ['zh']];
    }
}
