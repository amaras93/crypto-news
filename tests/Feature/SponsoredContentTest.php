<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\Advertiser;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Tag;
use App\Models\User;
use App\Support\AdvertisingSettings;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\NewsDigests;
use App\Support\NewsFeed;
use App\Support\SponsoredContentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AdvertisingTestCase;

class SponsoredContentTest extends AdvertisingTestCase
{
    private function draft(): Article
    {
        $sponsor = Advertiser::factory()->create(['status' => 'active', 'public_brand_name' => 'Verified fixture sponsor']);

        return app(ArticleEditor::class)->save($this->admin, ['author_id' => $this->admin->id, 'tag_ids' => [],
            'translations' => ['en' => ['enabled' => true, 'title' => 'An editorial product announcement', 'slug' => 'sponsored-product-story',
                'excerpt' => 'A factual announcement.', 'body' => '<p>A reviewed product announcement.</p><p><a href="https://example.com/product">Visit sponsor</a></p>']],
            'sponsorship' => ['classification' => 'sponsored', 'sponsor_id' => $sponsor->id, 'disclosures' => ['en' => 'This article is paid for by the named sponsor. The commercial relationship is disclosed explicitly.']]]);
    }

    private function publish(Article $article): Article
    {
        app(ArticlePublication::class)->transition($this->admin, $article, 'submit_review');
        app(SponsoredContentService::class)->review($this->admin, $article->fresh(), $this->reviewData());
        app(ArticlePublication::class)->transition($this->admin, $article->fresh(), 'approve');

        return app(ArticlePublication::class)->transition($this->admin, $article->fresh(), 'publish');
    }

    public function test_sponsorship_classification_requires_commercial_and_existing_editorial_review_before_publication(): void
    {
        $article = $this->draft();
        $this->assertSame(ArticleStatus::Draft, $article->status);
        try {
            app(ArticlePublication::class)->transition($this->admin, $article, 'publish');
            $this->fail('Unreviewed sponsorship published.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $article = $this->publish($article);
        $this->get('/en/news/sponsored-product-story')->assertOk()->assertSee(__('advertising.sponsored'))
            ->assertSee('Verified fixture sponsor')->assertSee('commercial relationship is disclosed explicitly.')
            ->assertSee('rel="sponsored nofollow noopener noreferrer"', false)->assertSee('rel="canonical"', false)->assertSee('"sponsor"', false);
    }

    public function test_edits_invalidate_sponsorship_review_and_return_a_published_article_to_draft(): void
    {
        $article = $this->publish($this->draft());
        $data = app(ArticleEditor::class)->formData($article);
        $data['translations']['en']['title'] = 'A changed paid headline';
        app(ArticleEditor::class)->save($this->admin, $data, $article);
        $this->assertSame(ArticleStatus::Draft, $article->fresh()->status);
        $this->assertNull($article->fresh()->sponsorship_review_hash);
        $this->get('/en/news/sponsored-product-story')->assertNotFound();
    }

    public function test_saving_unchanged_content_does_not_destroy_a_current_review(): void
    {
        $article = $this->publish($this->draft());
        $hash = $article->sponsorship_review_hash;
        app(ArticleEditor::class)->save($this->admin, app(ArticleEditor::class)->formData($article), $article);
        $this->assertSame($hash, $article->fresh()->sponsorship_review_hash);
        $this->get('/en/news/sponsored-product-story')->assertOk();
    }

    public function test_editors_cannot_change_paid_classification_or_approve_sponsorship(): void
    {
        $article = $this->draft();
        $editor = User::factory()->editor()->create();
        $this->expectException(HttpException::class);
        app(SponsoredContentService::class)->configure($editor, $article, ['classification' => null]);
    }

    public function test_incomplete_translated_disclosures_block_submission_to_review(): void
    {
        $article = $this->draft();
        ArticleTranslation::factory()->create(['article_id' => $article->id, 'locale' => 'ja']);
        $this->expectException(ValidationException::class);
        app(ArticlePublication::class)->transition($this->admin, $article->fresh(), 'submit_review');
    }

    public function test_paid_stories_are_labeled_in_cards_and_are_not_independent_editorial_picks_or_digests(): void
    {
        $article = $this->publish($this->draft());
        $article->tags()->attach(Tag::factory()->create(['slug' => 'editors-pick']));
        $article->tags()->attach(Tag::factory()->create(['slug' => 'analysis']));
        $this->get('/en')->assertOk()->assertSee(__('advertising.sponsored'));
        $this->assertFalse(app(NewsFeed::class)->selected('en', 'editors-pick', 4)->contains('id', $article->id));
        $article->update(['published_at' => now()->utc()->startOfDay()->subHour()]);
        $this->assertNotContains($article->id, app(NewsDigests::class)->select('en', 'daily')->modelKeys());
    }

    public function test_unreviewed_sponsored_rows_never_leak_through_public_scopes(): void
    {
        $article = $this->draft();
        $article->forceFill(['status' => ArticleStatus::Published, 'published_at' => now()])->save();
        $this->get('/en/news/sponsored-product-story')->assertNotFound();
        $this->get('/en/news')->assertDontSee('An editorial product announcement');
    }

    public function test_global_ad_shutdown_does_not_remove_existing_sponsorship_disclosures(): void
    {
        $article = $this->publish($this->draft());
        app(AdvertisingSettings::class)->stop($this->admin);
        $this->get('/en/news/sponsored-product-story')->assertOk()->assertSee(__('advertising.sponsored'))->assertSee('Verified fixture sponsor');
    }

    public function test_editor_form_data_can_be_saved_unchanged_but_cannot_reclassify_an_article(): void
    {
        $editor = User::factory()->editor()->create();
        $article = app(ArticleEditor::class)->save($editor, ['author_id' => $editor->id, 'translations' => ['en' => [
            'enabled' => true, 'title' => 'Existing editorial draft', 'slug' => 'existing-editorial-draft', 'body' => '<p>Independent reporting.</p>']]]);
        $data = app(ArticleEditor::class)->formData($article);
        app(ArticleEditor::class)->save($editor, $data, $article);
        $this->assertNull($article->fresh()->sponsor_classification);
        $data['sponsorship'] = ['classification' => 'sponsored', 'sponsor_id' => Advertiser::factory()->create()->id, 'disclosures' => ['en' => 'An unauthorized attempt to change paid classification.']];
        $this->expectException(AuthorizationException::class);
        app(ArticleEditor::class)->save($editor, $data, $article);
    }
}
