<?php

namespace Tests\Feature;

use App\ArticleStatus;
use App\ContentSource;
use App\Models\Article;
use App\Support\AiDrafting;
use App\Support\AiFailure;
use App\Support\ArticlePublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\AiTestCase;

class AiPipelineTest extends AiTestCase
{
    public function test_offline_generation_is_attributed_private_and_saved_through_existing_editorial_services(): void
    {
        $run = $this->complete($this->pending());
        $article = $run->article;
        $this->assertSame(ContentSource::Ai, $article->content_source);
        $this->assertSame(ArticleStatus::PendingReview, $article->status);
        $this->assertSame($this->candidate->id, $article->ai_candidate_id);
        $this->assertNull($article->published_at);
        $this->assertNull($article->approved_at);
        $this->assertSame('pending', $article->translation('en')->ai_review_status);
        $this->assertStringContainsString('https://example.org/', $article->translation('en')->body);
        $this->assertSame(0, Article::published()->count());
        $this->assertNull($run->actual_microusd);
        $this->assertSame(800, $run->simulated_usage['input_tokens']);
        $this->assertDatabaseCount('editorial_events', 2);
        $this->assertDatabaseCount('ai_spend_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_paid_adapter_with_http_fake_records_actual_usage_and_never_publishes(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['api.openai.com/*' => Http::response($this->response($run))]);
        $run = $this->complete($run);
        $this->assertSame(800, $run->input_tokens);
        $this->assertSame(200, $run->cached_input_tokens);
        $this->assertSame(2265, $run->actual_microusd);
        $this->assertSame('settled', $run->reservation->status);
        $this->assertGreaterThan($run->actual_microusd, $run->estimated_microusd);
        $this->assertSame(0, Article::published()->count());
        $this->expectException(ValidationException::class);
        app(ArticlePublication::class)->transition($this->admin, $run->article, 'publish');
    }

    public function test_one_candidate_cannot_create_duplicate_work_or_articles_even_after_job_replays(): void
    {
        $pipeline = app(AiDrafting::class);
        $key = Str::uuid()->toString();
        $run = $pipeline->generate($this->admin, $this->candidate, simulated: true, requestKey: $key, dispatch: false);
        $same = $pipeline->generate($this->admin, $this->candidate, simulated: true, requestKey: $key, dispatch: false);
        $this->assertSame($run->id, $same->id);
        $run = $this->complete($run);
        $pipeline->process($run->id, false);
        $pipeline->validate($run->id, false);
        $pipeline->store($run->id);
        $this->assertDatabaseCount('articles', 1);
        $this->assertDatabaseCount('article_translations', 1);
        $this->expectException(AiFailure::class);
        $this->pending();
    }

    public function test_even_human_approved_simulated_articles_cannot_be_published(): void
    {
        $run = $this->complete($this->pending());
        $this->approveEnglish($run);
        $this->expectException(ValidationException::class);
        app(ArticlePublication::class)->transition($this->admin, $run->article, 'publish');
    }
}
