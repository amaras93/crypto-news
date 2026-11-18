<?php

namespace Tests\Feature;

use App\Models\AdvertisingAudit;
use App\Models\User;
use App\Support\AdCreative;
use App\Support\AdvertisingManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AdvertisingTestCase;

class AdvertisingSecurityTest extends AdvertisingTestCase
{
    public static function unsafeUrls(): array
    {
        return array_map(fn (string $url): array => [$url], ['javascript:alert(1)', 'data:text/html,boom', 'http://example.com', '//example.com',
            'https://user:password@example.com', 'https://127.0.0.1', 'https://[::1]/', 'https://localhost/', 'https://internal.local/',
            'https://example.com:444/', 'https://example.com/#fragment', 'https://example.com/?api_key=secret', 'https://example.com/%0d%0aHeader',
            'https://example.com/\\bad']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_destinations_are_rejected_without_outbound_requests(string $url): void
    {
        $this->expectException(ValidationException::class);
        app(AdvertisingManager::class)->safeUrl($url);
    }

    public function test_creatives_are_reencoded_and_original_names_and_metadata_are_discarded(): void
    {
        $file = UploadedFile::fake()->image('original.php.png', 728, 90);
        $contents = file_get_contents($file->getRealPath()).'<?php echo "PAYLOAD"; ?>';
        $path = app(AdCreative::class)->store($this->admin, UploadedFile::fake()->createWithContent('original.php.png', $contents));
        $this->assertMatchesRegularExpression('#^advertising/creatives/[a-f0-9-]{36}\\.png$#', $path);
        $bytes = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('PAYLOAD', $bytes);
        $this->assertSame('image/png', getimagesizefromstring($bytes)['mime']);
    }

    public function test_svg_executable_and_oversized_creatives_are_rejected(): void
    {
        foreach ([UploadedFile::fake()->createWithContent('creative.svg', '<svg><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('creative.php', 20, 'application/x-httpd-php'), UploadedFile::fake()->image('large.png', 2500, 100)] as $file) {
            try {
                app(AdCreative::class)->store($this->admin, $file);
                $this->fail('Unsafe creative was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_administrators_can_upload_manage_or_approve_campaigns(): void
    {
        $campaign = $this->campaign(approve: false);
        foreach ([User::factory()->create(), User::factory()->editor()->create()] as $user) {
            try {
                app(AdvertisingManager::class)->approve($user, $campaign, $this->reviewData());
                $this->fail('Unauthorized approval.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            try {
                app(AdCreative::class)->store($user, UploadedFile::fake()->image('creative.png'));
                $this->fail('Unauthorized upload.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_sensitive_offers_require_all_manual_attestations_and_unverified_offers_are_blocked(): void
    {
        $campaign = $this->campaign(['risk_category' => 'unverified_offer'], false);
        $this->expectException(ValidationException::class);
        app(AdvertisingManager::class)->approve($this->admin, $campaign, $this->reviewData());
    }

    public function test_guaranteed_returns_in_creative_copy_are_prohibited_even_when_checkboxes_are_ticked(): void
    {
        $campaign = $this->campaign(['alt_text' => ['en' => 'Guaranteed profits for every investor']], false);
        $this->expectException(ValidationException::class);
        app(AdvertisingManager::class)->approve($this->admin, $campaign, $this->reviewData());
    }

    public function test_approval_state_cannot_be_submitted_through_campaign_edit_fields(): void
    {
        $data = $this->campaignData(['status' => 'active', 'approved_by' => $this->admin->id]);
        $this->expectException(ValidationException::class);
        app(AdvertisingManager::class)->campaign($this->admin, $data);
    }

    public function test_private_commercial_fields_are_encrypted_hidden_and_changes_are_audited(): void
    {
        $campaign = $this->campaign();
        $this->assertNotSame('1250.50', $campaign->getRawOriginal('contract_amount'));
        $this->assertArrayNotHasKey('contract_amount', $campaign->toArray());
        $this->assertArrayNotHasKey('review_notes', $campaign->toArray());
        $this->assertArrayNotHasKey('business_name', $campaign->advertiser->toArray());
        $this->assertDatabaseCount('advertising_audits', 2);
        $this->assertStringNotContainsString('Human review fixture', AdvertisingAudit::latest('id')->first()->getRawOriginal('details'));
    }
}
