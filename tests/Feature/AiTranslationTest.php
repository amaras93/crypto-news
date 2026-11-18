<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\ArticleStatus;
use App\Models\AiPipelineSetting;
use App\Models\ArticleTranslation;
use App\Models\CandidateEvidence;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use App\Support\AiFailure;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\FakeAiDraftProvider;
use App\Support\PublicUrl;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\AiTestCase;

class AiTranslationTest extends AiTestCase
{
    public function test_all_seven_translations_are_independent_private_drafts_with_seo_and_attribution(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $languages = ['ru', 'fr', 'de', 'es', 'it', 'ja', 'zh'];
        $runs = app(AiDrafting::class)->translate($this->admin, $run->article, $languages, dispatch: false);
        foreach ($runs as $translationRun) {
            $stored = $this->complete($translationRun);
            $translation = $stored->article->translation($stored->locale);
            $this->assertSame('pending', $translation->ai_review_status);
            $this->assertNotEmpty($translation->meta_title);
            $this->assertNotEmpty($translation->meta_description);
            $this->assertStringContainsString('https://example.org/', $translation->body);
            $this->assertStringContainsString('Sandbox Exchange', $translation->body);
            $this->assertFalse(app(PublicUrl::class)->isPublicTranslation($translation));
        }
        $article = $run->article->fresh(['translations']);
        $this->assertCount(8, $article->translations);
        $this->assertNotSame($article->translation('en')->title, $article->translation('fr')->title);
        $this->assertSame('approved', $article->translation('en')->ai_review_status);
        $this->assertSame(ArticleStatus::Draft, $article->status);
        Http::assertNothingSent();
    }

    public function test_translation_requires_a_current_explicit_human_english_fact_review(): void
    {
        $run = $this->complete($this->pending());
        $this->expectException(AiFailure::class);
        app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], dispatch: false);
    }

    public function test_partial_translation_failure_preserves_successful_languages_and_english(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        $englishDigest = $run->article->translation('en')->contentDigest();
        $runs = app(AiDrafting::class)->translate($this->admin, $run->article, ['ru', 'fr', 'de'], dispatch: false);
        foreach ($runs as $next) {
            $body = $this->response($next);
            if ($next->locale === 'fr') {
                $body['output'][0]['content'] = [['type' => 'refusal', 'refusal' => 'Fixture refusal']];
            }
            $this->fakeProvider($body);
            app(AiDrafting::class)->process($next->id, false);
            app(AiDrafting::class)->validate($next->id, false);
            app(AiDrafting::class)->store($next->id);
        }
        $article = $run->article->fresh(['translations']);
        $this->assertCount(3, $article->translations);
        $this->assertNull($article->translation('fr'));
        $this->assertSame($englishDigest, $article->translation('en')->contentDigest());
        $this->assertSame(AiRunStatus::Failed, $runs[1]->fresh()->status);
        $this->assertSame(AiRunStatus::Stored, $runs[0]->fresh()->status);
        $this->assertSame(AiRunStatus::Stored, $runs[2]->fresh()->status);
    }

    public function test_human_edited_translation_cannot_be_overwritten_without_confirmation(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $translationRun = app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], dispatch: false)[0];
        $this->complete($translationRun);
        $article = $run->article->fresh(['translations']);
        $editor = app(ArticleEditor::class);
        $data = $editor->formData($article);
        $data['translations']['ru']['title'] = 'Редакционная правка для учебного примера';
        $article = $editor->save($this->admin, $data, $article);
        $this->assertNotNull($article->translation('ru')->ai_human_edited_at);
        try {
            app(AiDrafting::class)->translate($this->admin, $article, ['ru'], dispatch: false);
            $this->fail('Human edit replaced silently');
        } catch (AiFailure $failure) {
            $this->assertSame('human_overwrite_confirmation_required', $failure->reason);
        }
        $next = app(AiDrafting::class)->translate($this->admin, $article, ['ru'], true, false)[0];
        $this->complete($next);
        $this->assertNotSame($data['translations']['ru']['title'], $article->fresh(['translations'])->translation('ru')->title);
        $this->assertCount(2, $article->fresh(['translations'])->translations);
    }

    public function test_edits_after_queueing_are_not_lost_even_with_prior_overwrite_confirmation(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], true, false)[0];
        $article = $run->article->fresh(['translations']);
        $data = app(ArticleEditor::class)->formData($article);
        $data['translations']['ru'] = $data['translations']['en'];
        $data['translations']['ru']['title'] = 'Human draft written after queueing';
        $data['translations']['ru']['slug'] = 'human-russian-draft';
        app(ArticleEditor::class)->save($this->admin, $data, $article);
        app(AiDrafting::class)->process($next->id, false);
        $this->assertSame('translation_changed_after_queueing', $next->fresh()->error_code);
        $this->assertSame('Human draft written after queueing', $article->fresh(['translations'])->translation('ru')->title);
    }

    public function test_english_changes_invalidate_all_translation_reviews_and_pending_work(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false)[0];
        $article = $run->article->fresh(['translations']);
        $data = app(ArticleEditor::class)->formData($article);
        $data['translations']['en']['title'] = 'Updated fictional documentation for editorial training';
        $article = app(ArticleEditor::class)->save($this->admin, $data, $article);
        $this->assertSame('pending', $article->translation('en')->ai_review_status);
        $this->assertSame(ArticleStatus::Draft, $article->status);
        app(AiDrafting::class)->process($next->id, false);
        $this->assertSame('english_source_changed', $next->fresh()->error_code);
    }

    public function test_individual_pending_translations_never_leak_into_public_seo_or_sitemaps(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false)[0];
        $this->fakeProvider($this->response($next));
        $this->complete($next);
        $article = $run->article->fresh(['translations']);
        app(ArticlePublication::class)->transition($this->admin, $article, 'publish');
        $english = $article->translation('en');
        $french = $article->translation('fr');
        $this->get(route('news.show', ['locale' => 'en', 'slug' => $english->slug]))->assertOk()
            ->assertDontSee('<link rel="alternate" hreflang="fr"', false)->assertSee('AI-assisted', false);
        $this->get(route('news.show', ['locale' => 'fr', 'slug' => $french->slug]))->assertNotFound();
        $xml = $this->get(route('sitemap.articles', ['start' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString($english->slug, $xml);
        $this->assertStringNotContainsString($french->slug, $xml);
        $this->get('/fr/news')->assertOk()->assertDontSee($french->title);
    }

    public function test_a_new_translation_needs_separate_review_before_it_can_become_public(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['zh'], dispatch: false)[0];
        $this->complete($next);
        $article = $run->article->fresh(['translations']);
        app(AiEditorialReviewService::class)->review($this->admin, $article, 'zh', true, true,
            'Checked the Chinese translation against approved English, all source claims, symbols and attribution.');
        $this->assertTrue(app(PublicUrl::class)->isPublicTranslation($article->fresh(['translations'])->translation('zh')));
        $this->assertDatabaseCount('ai_editorial_reviews', 2);
    }

    public function test_section_regeneration_is_a_proposal_and_applying_it_invalidates_human_approval(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $article = $run->article->fresh(['translations']);
        $before = $article->translation('en')->body;
        $next = app(AiDrafting::class)->section($this->admin, $article, 'story', false);
        $this->complete($next);
        $this->assertSame($before, $article->fresh(['translations'])->translation('en')->body);
        $updated = app(AiDrafting::class)->applySection($this->admin, $next->fresh(), true);
        $this->assertSame('pending', $updated->translation('en')->ai_review_status);
        $this->assertNotNull($next->fresh()->section_applied_at);
        $this->expectException(AiFailure::class);
        app(AiDrafting::class)->applySection($this->admin, $next->fresh(), true);
    }

    public function test_invented_translation_numbers_are_blocked_without_touching_other_content(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['de'], dispatch: false)[0];
        $doc = app(FakeAiDraftProvider::class)->fixture($next);
        $doc['excerpt'] .= ' 99%.';
        $this->fakeProvider($this->response($next, $doc));
        app(AiDrafting::class)->process($next->id, false);
        app(AiDrafting::class)->validate($next->id, false);
        $this->assertSame(AiRunStatus::QualityBlocked, $next->fresh()->status);
        $this->assertContains('translation_numbers_or_dates_changed', $next->fresh()->quality_report['critical']);
        $this->assertDatabaseCount('article_translations', 1);
    }

    public function test_lowering_the_language_ceiling_after_queueing_prevents_paid_translation_requests(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], dispatch: false)[0];
        AiPipelineSetting::find(1)->update(['max_translation_languages' => 0]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        app(AiDrafting::class)->process($next->id, false);
        $this->assertSame('translation_language_limit', $next->fresh()->error_code);
        $this->assertDatabaseCount('ai_spend_reservations', 1);
        Http::assertNothingSent();
    }

    public function test_the_saved_language_ceiling_cannot_be_exceeded_by_another_language_request(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $this->approveEnglish($run);
        AiPipelineSetting::find(1)->update(['max_translation_languages' => 1]);
        app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], dispatch: false);
        try {
            app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false);
            $this->fail('Language ceiling exceeded');
        } catch (AiFailure $failure) {
            $this->assertSame('translation_language_limit', $failure->reason);
        }
        $this->assertDatabaseCount('ai_generation_runs', 2);
    }

    public function test_human_translation_seo_cannot_add_a_figure_absent_from_approved_english(): void
    {
        $claim = CandidateEvidence::first();
        $claim->update(['figures' => ['42'], 'claim' => $claim->claim.' The permitted fictional source also specifies 42 training examples.']);
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false)[0];
        $this->complete($next);
        $article = $run->article->fresh(['translations']);
        $data = app(ArticleEditor::class)->formData($article);
        $data['translations']['fr']['meta_description'] .= ' Il existe 42 exemples.';
        $article = app(ArticleEditor::class)->save($this->admin, $data, $article);
        $this->expectException(AiFailure::class);
        app(AiEditorialReviewService::class)->review($this->admin, $article, 'fr', true, true,
            'Checked the translated text against its source and attempted an added SEO detail from separate evidence.');
    }

    public function test_human_translation_review_rechecks_proper_names_against_english(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['fr'], dispatch: false)[0];
        $this->complete($next);
        $article = $run->article->fresh(['translations']);
        $data = app(ArticleEditor::class)->formData($article);
        foreach ($data['translations']['fr'] as $field => $value) {
            if (is_string($value)) {
                $data['translations']['fr'][$field] = str_replace(['Sandbox Exchange', 'SBX'], ['organisation fictive', 'plateforme'], $value);
            }
        }
        $article = app(ArticleEditor::class)->save($this->admin, $data, $article);
        $this->expectException(AiFailure::class);
        app(AiEditorialReviewService::class)->review($this->admin, $article, 'fr', true, true,
            'Review attempted after all exact source proper names were removed from a manually edited translation.');
    }

    public function test_historical_armenian_translation_is_preserved_during_ai_updates(): void
    {
        $run = $this->complete($this->pending());
        ArticleTranslation::factory()->create(['article_id' => $run->article_id, 'locale' => 'hy', 'title' => 'Պահպանված նյութ']);
        $before = $run->article->fresh(['translations'])->translation('hy')->getAttributes();
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($this->admin, $run->article, ['ru'], dispatch: false)[0];
        $this->complete($next);
        $this->assertSame($before, $run->article->fresh(['translations'])->translation('hy')->getAttributes());
    }
}
