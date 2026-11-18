<?php

namespace Tests\Feature;

use App\Filament\Pages\AiNewsControlCenter;
use App\Filament\Resources\AiGenerationRuns\Pages\ManageAiGenerationRuns;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\CandidateEvidence\Pages\ManageCandidateEvidence;
use App\Filament\Resources\NewsCandidates\Pages\ManageNewsCandidates;
use App\Jobs\GenerateAiDraft;
use App\Models\AiEditorialReview;
use App\Models\AiGenerationRun;
use App\Models\AiPipelineSetting;
use App\Models\Article;
use App\Models\CandidateEvidence;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use App\Support\AiSettings;
use App\Support\ArticlePublication;
use Database\Seeders\AiPipelineSettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\AiTestCase;

class AiAdministrationTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    public function test_administrator_pages_render_actual_states_without_disclosing_credentials(): void
    {
        $run = $this->complete($this->pending());
        foreach (['/admin/ai-news-control-center', '/admin/ai-generation-runs', '/admin/candidate-evidence', '/admin/articles/'.$run->article_id.'/edit'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('offline-test-credential');
        }
        Livewire::test(ManageAiGenerationRuns::class)->callTableAction('view', $run)->assertHasNoErrors();
        $this->get('/admin/ai-news-control-center')->assertSee('SIMULATED')->assertSee('Stop all new AI requests');
        Http::assertNothingSent();
    }

    public function test_readers_and_guests_cannot_access_ai_administration(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/admin/ai-news-control-center', '/admin/ai-generation-runs', '/admin/candidate-evidence'] as $url) {
            $this->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get('/admin/ai-generation-runs')->assertRedirect('/admin/login');
    }

    public function test_editors_can_read_control_status_but_cannot_change_flags_or_emergency_stop(): void
    {
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor);
        $this->get('/admin/ai-news-control-center')->assertOk()->assertDontSee('Save AI controls');
        Livewire::test(AiNewsControlCenter::class)->call('saveAi')->assertForbidden();
        Livewire::test(AiNewsControlCenter::class)->call('stopAi')->assertForbidden();
        $this->assertDatabaseCount('ai_pipeline_settings', 0);
    }

    public function test_native_control_center_saves_separate_controls_without_implicitly_activating_requests(): void
    {
        $state = app(AiSettings::class)->current();
        unset($state['updated_by']);
        $state['generation_enabled'] = true;
        $state['model'] = 'configured-but-stopped';
        Livewire::test(AiNewsControlCenter::class)->fillForm($state, 'aiForm')->call('saveAi')->assertHasNoErrors();
        $this->assertTrue(AiPipelineSetting::find(1)->generation_enabled);
        $this->assertFalse(app(AiDrafting::class)->isOperational());
        Livewire::test(AiNewsControlCenter::class)->call('stopAi')->assertHasNoErrors();
        $this->assertTrue(AiPipelineSetting::find(1)->emergency_stop);
        Http::assertNothingSent();
    }

    public function test_native_candidate_action_queues_only_an_explicitly_authorized_draft(): void
    {
        $this->live();
        Livewire::test(ManageNewsCandidates::class)->callTableAction('generate_draft', $this->candidate, data: [
            'author_id' => $this->admin->id, 'allow_editor_regeneration' => false,
        ])->assertHasNoErrors();
        Queue::assertPushed(GenerateAiDraft::class, 1);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_generation_has_native_action_validation_instead_of_a_server_error(): void
    {
        Livewire::test(ManageNewsCandidates::class)->callTableAction('generate_draft', $this->candidate, data: [
            'author_id' => $this->admin->id, 'allow_editor_regeneration' => false,
        ])->assertHasActionErrors(['ai']);
        $this->assertDatabaseCount('ai_generation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_native_evidence_creation_and_edit_record_human_verifier_and_audited_claim(): void
    {
        $data = ['news_candidate_id' => $this->candidate->id,
            'claim' => 'The fictional training exercise provides original permitted documentation for editors to inspect source attribution without using live market news.',
            'reference_ids' => [$this->candidate->references()->first()->id], 'entities' => ['Sandbox Exchange', 'SBX'],
            'figures' => [], 'material' => true, 'review_notes' => 'Verified from the original first-party offline training documentation.', 'confirmed' => true];
        Livewire::test(ManageCandidateEvidence::class)->callAction('create', data: $data)->assertHasNoErrors();
        $claim = CandidateEvidence::latest('id')->first();
        $this->assertSame($this->admin->id, $claim->verified_by);
        $this->assertSame($data['claim'], $claim->claim);
        $data['claim'] .= ' This remains a private exercise.';
        Livewire::test(ManageCandidateEvidence::class)->callTableAction('edit', $claim, data: $data)->assertHasNoErrors();
        $this->assertSame($data['claim'], $claim->fresh()->claim);
    }

    public function test_native_article_review_and_translation_actions_use_existing_saved_content(): void
    {
        $run = $this->complete($this->pending());
        Livewire::test(EditArticle::class, ['record' => $run->article_id])->callAction('ai_review', data: [
            'locale' => 'en', 'decision' => 'approve', 'confirmed' => true,
            'notes' => 'Verified all source facts and attribution against the original fictional evidence for this private demonstration.',
        ])->assertHasNoErrors();
        $this->assertSame('approved', $run->article->fresh(['translations'])->translation('en')->ai_review_status);
        Livewire::test(EditArticle::class, ['record' => $run->article_id])->callAction('ai_translate', data: [
            'locales' => ['ru'], 'overwrite' => false,
        ])->assertHasNoErrors();
        Queue::assertPushed(GenerateAiDraft::class, 1);
    }

    public function test_actual_editor_may_regenerate_own_draft_only_under_explicit_original_admin_authorization(): void
    {
        $editor = User::factory()->editor()->create();
        $run = app(AiDrafting::class)->generate($this->admin, $this->candidate, $editor, true, true, dispatch: false);
        $run = $this->complete($run);
        $this->approveEnglish($run);
        $next = app(AiDrafting::class)->translate($editor, $run->article, ['ru'], dispatch: false)[0];
        $this->assertSame($this->admin->id, $next->billing_user_id);
        $this->assertSame($editor->id, $next->requested_by);
        $this->complete($next);
        $this->actingAs($editor)->get('/admin/ai-generation-runs')->assertOk();
        $this->expectException(AuthorizationException::class);
        app(ArticlePublication::class)->transition($editor, $run->article, 'publish');
    }

    public function test_missing_delegation_blocks_editor_regeneration_even_for_their_own_article(): void
    {
        $editor = User::factory()->editor()->create();
        $run = $this->complete(app(AiDrafting::class)->generate($this->admin, $this->candidate, $editor, true, false, dispatch: false));
        $this->approveEnglish($run);
        try {
            app(AiDrafting::class)->translate($editor, $run->article, ['ru'], dispatch: false);
            $this->fail('Unapproved spending delegation accepted');
        } catch (HttpException $failure) {
            $this->assertSame(403, $failure->getStatusCode());
        }
        $this->assertDatabaseCount('ai_generation_runs', 1);
    }

    public function test_unrelated_editor_cannot_inspect_or_regenerate_another_authors_draft(): void
    {
        $run = $this->complete($this->pending());
        $other = User::factory()->editor()->create();
        $this->actingAs($other);
        $this->get('/admin/ai-generation-runs')->assertOk()->assertDontSee($run->model);
        $this->get('/admin/articles/'.$run->article_id.'/edit')->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(AiEditorialReviewService::class)->review($other, $run->article, 'en', true, true, str_repeat('Verified sources. ', 4));
    }

    public function test_fact_review_requires_explicit_confirmation_and_rejection_remains_private(): void
    {
        $run = $this->complete($this->pending());
        try {
            app(AiEditorialReviewService::class)->review($this->admin, $run->article, 'en', true, false, str_repeat('Reviewed sources. ', 4));
            $this->fail('Confirmation omitted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_editorial_reviews', 0);
        }
        app(AiEditorialReviewService::class)->review($this->admin, $run->article, 'en', false, false, str_repeat('Needs factual corrections. ', 3));
        $this->assertSame('rejected', $run->article->fresh(['translations'])->translation('en')->ai_review_status);
        $this->assertSame(0, Article::published()->count());
    }

    public function test_offline_estimator_uses_verified_configured_prices_without_requests(): void
    {
        $this->artisan('ai:estimate')->assertFailed();
        $this->live();
        $this->artisan('ai:estimate', ['--model' => 'test-model'])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_daily_statistics_use_execution_and_completion_dates_instead_of_queue_creation_date(): void
    {
        $this->live();
        $run = $this->pending(false);
        $this->fakeProvider($this->response($run));
        $run = $this->complete($run);
        $run->forceFill(['created_at' => now()->subDay()])->save();
        $statistics = app(AiNewsControlCenter::class)->aiStatistics();
        $this->assertSame(1, $statistics['Drafts generated today (UTC, excludes simulations)']);
        $this->assertSame(1, $statistics['Provider requests today']);
        $this->assertSame('800 / 200 / 0 / 400', $statistics['Recorded input / cached / write / output tokens today']);
        $this->assertSame(2265, $statistics['Recorded spending today (micro-USD, configured rates)']);
    }

    public function test_factories_and_settings_seeder_default_to_non_public_non_billable_records(): void
    {
        $settings = AiPipelineSetting::factory()->create();
        $this->assertTrue($settings->emergency_stop);
        $this->assertFalse($settings->generation_enabled);
        $settings->update(['max_articles_per_day' => 2]);
        $this->seed(AiPipelineSettingSeeder::class);
        $this->assertSame(2, $settings->fresh()->max_articles_per_day);
        $this->assertTrue(AiGenerationRun::factory()->create()->is_simulated);
        $this->assertNotNull(CandidateEvidence::factory()->create()->verified_by);
        $this->assertSame('rejected', AiEditorialReview::factory()->create()->decision);
        $this->assertSame(0, Article::published()->count());
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_offline_demo_uses_a_separate_database_and_never_writes_into_business_records(): void
    {
        $counts = [User::count(), Article::count(), CandidateEvidence::count()];
        $this->artisan('ai:demo')->assertSuccessful();
        $this->assertSame($counts, [User::count(), Article::count(), CandidateEvidence::count()]);
        $this->assertDatabaseCount('ai_generation_runs', 0);
        Http::assertNothingSent();
    }
}
