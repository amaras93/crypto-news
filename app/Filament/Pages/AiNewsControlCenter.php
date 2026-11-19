<?php

namespace App\Filament\Pages;

use App\AiRunStatus;
use App\Filament\Resources\Articles\ArticleResource;
use App\IngestionStatus;
use App\Models\AiBudgetPeriod;
use App\Models\AiGenerationRun;
use App\Models\Article;
use App\Models\EditorialEvent;
use App\Models\IngestionRun;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\AiSettings;
use App\Support\DiscoverySettingsManager;
use App\Support\NewsDiscovery;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class AiNewsControlCenter extends Page
{
    protected string $view = 'filament.pages.ai-news-control-center';

    protected static ?string $title = 'AI News Control Center';

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public ?array $aiData = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isEditorial();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(app(DiscoverySettingsManager::class)->current());
        $this->aiForm->fill(app(AiSettings::class)->current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Toggle::make('enabled')->label('Enable approved-source discovery'),
            TextInput::make('default_polling_minutes')->numeric()->required()->minValue(15)->maxValue(1440),
            TextInput::make('max_candidates_per_run')->label('Maximum candidates per source run')->numeric()->required()->minValue(1)->maxValue(100),
            TextInput::make('max_sources_per_run')->numeric()->required()->minValue(1)->maxValue(5),
        ])->disabled(fn (): bool => ! auth()->user()?->is_admin);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        app(DiscoverySettingsManager::class)->save(auth()->user(), $this->form->getState());
        Notification::make()->title('Discovery settings saved')->success()->send();
    }

    public function discover(): void
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        $count = app(NewsDiscovery::class)->queueDue(auth()->user());
        Notification::make()->title('Queued source runs: '.$count)->send();
    }

    public function aiForm(Schema $schema): Schema
    {
        return $schema->statePath('aiData')->components([
            Toggle::make('candidate_processing_enabled')->label('Allow approved candidate processing'),
            Toggle::make('generation_enabled')->label('English draft generation'),
            Toggle::make('translation_enabled')->label('Automatic translation (seven languages in automatic mode)'),
            Toggle::make('scheduled_generation_enabled')->label('Scheduled English generation'),
            Toggle::make('emergency_stop')->label('Emergency stop: halt AI processing and automatic publication'),
            Toggle::make('automatic_publishing')->label('Automatic publishing without editorial approval')->default(false)
                ->helperText('Server activation must also be enabled. All eight languages require automated evidence, quality and semantic checks. Disable to use human review.'),
            Select::make('enabled_category_ids')->label('Enabled news categories for automatic publishing')->multiple()
                ->options(fn (): array => ArticleResource::categoryOptions())->helperText('No categories selected means no unattended candidates.'),
            Select::make('provider')->options(['openai' => 'OpenAI Responses API'])->required(),
            TextInput::make('model')->maxLength(128)->helperText('An API model available to your account; must match verified server-side pricing. The Codex model does not configure this application.'),
            Select::make('author_id')->label('Actual editorial author for scheduled drafts')->options(fn (): array => User::where(fn ($query) => $query->where('is_admin', true)->orWhere('is_editor', true))->pluck('name', 'id')->all()),
            TextInput::make('daily_limit_microusd')->integer()->minValue(0)->required()->helperText('Integer micro-USD: 1,000,000 = USD 1. Server limits remain authoritative.'),
            TextInput::make('monthly_limit_microusd')->integer()->minValue(0)->required(),
            TextInput::make('max_articles_per_day')->integer()->minValue(0)->maxValue(1000)->required(),
            TextInput::make('max_concurrent_jobs')->integer()->minValue(1)->maxValue(5)->required(),
            TextInput::make('max_translation_languages')->integer()->minValue(0)->maxValue(7)->required(),
        ])->disabled(fn (): bool => ! auth()->user()?->is_admin);
    }

    public function saveAi(): void
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        app(AiSettings::class)->save(auth()->user(), $this->aiForm->getState());
        Notification::make()->title('AI controls saved; server flags, verified pricing, credentials and budgets must also permit requests')->send();
    }

    public function stopAi(): void
    {
        app(AiSettings::class)->stop(auth()->user());
        $this->aiForm->fill(app(AiSettings::class)->current());
        Notification::make()->title('Emergency stop active — no new AI calls')->success()->send();
    }

    public function aiStatistics(): array
    {
        $day = AiBudgetPeriod::where('period_key', 'day:'.now()->utc()->toDateString())->first();
        $month = AiBudgetPeriod::where('period_key', 'month:'.now()->utc()->format('Y-m'))->first();
        $runs = AiGenerationRun::where('is_simulated', false)->where('request_sent_at', '>=', now()->utc()->startOfDay());
        $limits = app(AiSettings::class)->limits();

        return [
            'Provider readiness' => app(AiDrafting::class)->isOperational() ? 'Configured locally; external acceptance unverified' : 'Stopped or incomplete configuration; no paid calls',
            'Drafts generated today (UTC, excludes simulations)' => AiGenerationRun::where('is_simulated', false)->where('completed_at', '>=', now()->utc()->startOfDay())->where('task', 'english')->where('status', AiRunStatus::Stored)->count(),
            'Pending translation runs' => AiGenerationRun::where('is_simulated', false)->where('task', 'translation')->whereIn('status', [AiRunStatus::Queued, AiRunStatus::Requesting, AiRunStatus::Received, AiRunStatus::Validated])->count(),
            'Failed / quality-blocked runs' => AiGenerationRun::where('is_simulated', false)->whereIn('status', [AiRunStatus::Failed, AiRunStatus::QualityBlocked])->count(),
            'Uncertain-cost runs (held reservations)' => AiGenerationRun::where('status', AiRunStatus::Uncertain)->count(),
            'Automatic publishing' => app(AiSettings::class)->automatic() ? 'Enabled; all validation and limits apply' : 'Disabled',
            'Held automatic candidates' => NewsCandidate::where('automation_status', 'held')->count(),
            'Held automatic articles' => Article::where('automation_status', 'held')->count(),
            'Automatically published today (UTC)' => EditorialEvent::where('action', 'automatic_published')->where('created_at', '>=', now()->utc()->startOfDay())->count(),
            'AI articles in optional human review' => Article::whereNotNull('ai_generation_run_id')->where('ai_simulated', false)
                ->where(fn ($query) => $query->whereNull('automation_status')->orWhere('automation_status', 'paused'))
                ->whereHas('translations', fn ($query) => $query->where('ai_review_status', 'pending'))->count(),
            'Estimated requests today (micro-USD)' => (int) (clone $runs)->sum('estimated_microusd'),
            'Recorded spending today (micro-USD, configured rates)' => $day?->actual_microusd ?? 0,
            'Held today (micro-USD)' => $day?->reserved_microusd ?? 0,
            'Remaining daily budget (micro-USD)' => max(0, $limits['daily'] - ($day?->actual_microusd ?? 0) - ($day?->reserved_microusd ?? 0)),
            'Remaining monthly budget (micro-USD)' => max(0, $limits['monthly'] - ($month?->actual_microusd ?? 0) - ($month?->reserved_microusd ?? 0)),
            'Provider requests today' => (int) (clone $runs)->whereNotNull('request_sent_at')->sum('request_count'),
            'Recorded input / cached / write / output tokens today' => implode(' / ', array_map(fn (string $field): int => (int) (clone $runs)->sum($field), ['input_tokens', 'cached_input_tokens', 'cache_write_tokens', 'output_tokens'])),
        ];
    }

    public function generationRuns(): Collection
    {
        return AiGenerationRun::query()->when(! auth()->user()?->is_admin, fn ($query) => $query->where('author_id', auth()->id()))->latest('id')->limit(10)->get();
    }

    /** @return array<string, int|string|null> */
    public function statistics(): array
    {
        $counts = NewsCandidate::query()->selectRaw("count(*) as total, sum(case when status in ('new','review_required','processing_failed') then 1 else 0 end) as pending, sum(case when status = 'rejected' then 1 else 0 end) as rejected, sum(case when status = 'duplicate' then 1 else 0 end) as duplicates")->first();

        return [
            'Enabled sources' => NewsSource::query()->where('enabled', true)->whereNotNull('approved_at')->count(),
            'Candidates discovered today (UTC)' => NewsCandidate::query()->where('discovered_at', '>=', now()->utc()->startOfDay())->count(),
            'Pending review' => (int) $counts->pending, 'Rejected' => (int) $counts->rejected, 'Duplicate warnings' => (int) $counts->duplicates,
            'Failed fetch runs' => IngestionRun::query()->where('status', IngestionStatus::Failed)->count(),
            'Last successful execution (UTC)' => IngestionRun::query()->where('status', IngestionStatus::Succeeded)->max('finished_at') ?? 'No successful runs',
        ];
    }

    /** @return Collection<int, IngestionRun> */
    public function recentRuns(): Collection
    {
        return IngestionRun::query()->with('source:id,name')->latest('id')->limit(10)->get();
    }
}
