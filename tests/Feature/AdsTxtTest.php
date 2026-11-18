<?php

namespace Tests\Feature;

use App\Models\AdvertisingSetting;
use App\Models\User;
use App\Support\AdvertisingSettings;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AdvertisingTestCase;

class AdsTxtTest extends AdvertisingTestCase
{
    public function test_default_ads_txt_contains_no_invented_seller_or_publisher(): void
    {
        $this->get('/ads.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('# No verified authorized sellers configured.')
            ->assertDontSee('google.com')->assertDontSee('pub-');
    }

    public function test_verified_google_seller_must_match_the_configured_publisher_and_is_audited(): void
    {
        config(['advertising.publisher_id' => 'ca-pub-0000000000000000']);
        $service = app(AdvertisingSettings::class);
        $service->save($this->admin, array_replace($service->current(), ['ads_txt' => 'google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0', 'ads_txt_verified' => true,
            'seller_review_notes' => 'Isolated test fixture: authorization evidence was explicitly checked.']));
        $this->get('/ads.txt')->assertOk()->assertSee('google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0');
        $this->assertDatabaseCount('advertising_audits', 1);
        config(['advertising.publisher_id' => '']);
        $this->get('/ads.txt')->assertDontSee('google.com');
    }

    public static function invalidEntries(): array
    {
        return [['google.com, pub-1111111111111111, DIRECT, f08c47fec0942fa0'], ['google.com, pub-0000000000000000, RESELLER, f08c47fec0942fa0'],
            ['google.com, pub-0000000000000000, DIRECT, bogus'], ['<script>alert(1)</script>, account, DIRECT'], ['example.com, account, UNKNOWN'],
            ['example.com, <script>, DIRECT'], ["example.com, account, DIRECT\nexample.com, account, DIRECT"]];
    }

    #[DataProvider('invalidEntries')]
    public function test_unauthorized_or_malformed_seller_lines_are_rejected(string $entries): void
    {
        config(['advertising.publisher_id' => 'ca-pub-0000000000000000']);
        $this->expectException(ValidationException::class);
        app(AdvertisingSettings::class)->sellerEntries($entries);
    }

    public function test_unverified_records_and_revoked_administrators_are_not_published(): void
    {
        AdvertisingSetting::factory()->create(['ads_txt' => 'example.com, actual-account-fixture, DIRECT', 'ads_txt_verified' => false, 'updated_by' => $this->admin->id]);
        $this->get('/ads.txt')->assertDontSee('actual-account-fixture');
        AdvertisingSetting::findOrFail(1)->forceFill(['ads_txt_verified' => true])->save();
        $this->get('/ads.txt')->assertSee('actual-account-fixture');
        $this->admin->forceFill(['is_admin' => false])->save();
        $this->get('/ads.txt')->assertDontSee('actual-account-fixture');
    }

    public function test_editors_cannot_publish_seller_records(): void
    {
        $this->expectException(HttpException::class);
        app(AdvertisingSettings::class)->save(User::factory()->editor()->create(), app(AdvertisingSettings::class)->current());
    }
}
