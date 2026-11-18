<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\Models\SavedArticle;
use App\Models\User;
use App\Support\ReaderEngagement;
use Tests\EngagementTestCase;

class SavedArticleTest extends EngagementTestCase
{
    public function test_bookmarks_are_unique_and_saved_language_is_preserved(): void
    {
        $story = $this->article('fr');
        $this->post('/fr/account/saved/'.$story->id)->assertRedirect();
        $this->post('/fr/account/saved/'.$story->id)->assertRedirect();
        $this->assertDatabaseCount('saved_articles', 1);
        $this->assertSame('fr', SavedArticle::sole()->locale);
        $this->get('/en/account/saved')->assertOk()->assertSee($story->translation('fr')->title);
    }

    public function test_drafts_and_missing_translations_cannot_be_bookmarked(): void
    {
        $draft = $this->article(published: false);
        $this->post('/en/account/saved/'.$draft->id)->assertNotFound();
        $french = $this->article('fr');
        $this->post('/en/account/saved/'.$french->id)->assertNotFound();
        $this->assertDatabaseCount('saved_articles', 0);
    }

    public function test_unpublishing_does_not_expose_a_saved_headline_or_body(): void
    {
        $story = $this->article();
        $saved = app(ReaderEngagement::class)->saveArticle($this->reader, $story->id, 'en');
        $story->update(['status' => ArticleStatus::Draft]);
        $this->get('/en/account/saved')->assertOk()->assertDontSee($story->translation('en')->title)
            ->assertSee(__('engagement.unavailable_article'));
        $this->delete('/en/account/saved/'.$saved->id)->assertRedirect();
        $this->assertDatabaseCount('saved_articles', 0);
    }

    public function test_another_users_bookmark_cannot_be_seen_or_removed(): void
    {
        $other = User::factory()->create();
        $story = $this->article();
        $saved = app(ReaderEngagement::class)->saveArticle($other, $story->id, 'en');
        $this->get('/en/account/saved')->assertOk()->assertDontSee($story->translation('en')->title);
        $this->delete('/en/account/saved/'.$saved->id)->assertForbidden();
        $this->assertDatabaseCount('saved_articles', 1);
    }

    public function test_soft_deleted_articles_remain_unavailable_without_destroying_the_bookmark(): void
    {
        $story = $this->article();
        app(ReaderEngagement::class)->saveArticle($this->reader, $story->id, 'en');
        $story->delete();
        $this->get('/en/account/saved')->assertOk()->assertDontSee($story->translation('en')->title);
        $this->assertDatabaseCount('saved_articles', 1);
    }
}
