<?php

namespace App\Filament\Resources\NewsCandidates;

use App\CandidateStatus;
use App\Filament\AiEditorialActions;
use App\Filament\EditorialForm;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\CandidateEvidence\CandidateEvidenceResource;
use App\Filament\Resources\NewsCandidates\Pages\ManageNewsCandidates;
use App\Filament\Resources\NewsSources\NewsSourceResource;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use App\Models\User;
use App\Support\AiDrafting;
use App\Support\CandidateReviewService;
use App\Support\FeedFailure;
use App\Support\FeedUrlSafety;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use UnitEnum;

class NewsCandidateResource extends Resource
{
    protected static ?string $model = NewsCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'headline';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('headline')->columnSpanFull(),
            TextEntry::make('original_url')->url(fn (NewsCandidate $record): ?string => static::safeLink($record->original_url))->openUrlInNewTab()->columnSpanFull(),
            TextEntry::make('canonical_url')->columnSpanFull(),
            TextEntry::make('summary')->label('Permitted original snippet')->columnSpanFull(),
            TextEntry::make('language'), TextEntry::make('status')->badge(), TextEntry::make('topic'),
            TextEntry::make('published_at')->dateTime()->label('Publisher time (UTC)'),
            TextEntry::make('discovered_at')->dateTime()->label('Discovery time (UTC)'),
            TextEntry::make('classification_explanation')->state(fn (NewsCandidate $record): string => json_encode($record->classification, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->columnSpanFull(),
            TextEntry::make('duplicate_of_id'), TextEntry::make('duplicate_reasons')->listWithLineBreaks(),
            RepeatableEntry::make('references')->label('Original source references (up to 20)')->schema([
                TextEntry::make('source.name'), TextEntry::make('headline'),
                TextEntry::make('original_url')->url(fn (CandidateSourceReference $record): ?string => static::safeLink($record->original_url))->openUrlInNewTab(),
                TextEntry::make('external_id'), TextEntry::make('language'), TextEntry::make('source.licensing_notes'),
                TextEntry::make('source_published_at')->dateTime(), TextEntry::make('first_seen_at')->dateTime(),
            ])->columnSpanFull(),
            TextEntry::make('reviewer.name')->label('Last reviewed by'), TextEntry::make('reviewed_at')->dateTime(),
        ]);
    }

    public static function safeLink(string $url): ?string
    {
        try {
            return app(FeedUrlSafety::class)->inspect($url)['url'];
        } catch (FeedFailure) {
            return null;
        }
    }

    public static function table(Table $table): Table
    {
        $actions = [ViewAction::make()];
        $actions[] = Action::make('generate_draft')->label('Queue English AI draft')->requiresConfirmation()
            ->visible(fn (NewsCandidate $record): bool => (bool) auth()->user()->is_admin && $record->status === CandidateStatus::ApprovedForDraft)
            ->modalDescription('Requires verified factual evidence, explicit source AI rights, credentials, pricing and budget. Creates a private draft for human review; never publishes.')
            ->schema([Select::make('author_id')->label('Actual editorial author')->required()->options(fn (): array => User::where(fn (Builder $query): Builder => $query->where('is_admin', true)->orWhere('is_editor', true))->pluck('name', 'id')->all())->default(fn (): int => auth()->id()),
                Checkbox::make('allow_editor_regeneration')->label('Authorize this author to request paid regeneration under my administrator budget limits.')])
            ->action(fn (NewsCandidate $record, array $data, Action $action) => AiEditorialActions::perform(fn () => app(AiDrafting::class)->generate(auth()->user(), $record,
                User::findOrFail($data['author_id']), allowEditorRegeneration: (bool) ($data['allow_editor_regeneration'] ?? false)), $action));
        $actions[] = Action::make('verified_evidence')->label('Inspect / record evidence')->url(fn (): string => CandidateEvidenceResource::getUrl());
        foreach (['approve_for_draft' => CandidateStatus::ApprovedForDraft, 'reject' => CandidateStatus::Rejected, 'return_to_review' => CandidateStatus::ReviewRequired] as $name => $status) {
            $actions[] = Action::make($name)->label(str_replace('_', ' ', ucfirst($name)))->requiresConfirmation()
                ->schema([Textarea::make('notes')->maxLength(2000)->helperText('Approval only prepares future drafting. It never creates or publishes an article. Explain any duplicate override.')])
                ->action(fn (NewsCandidate $record, array $data, Action $action): NewsCandidate => EditorialForm::saveForAction(fn (): NewsCandidate => app(CandidateReviewService::class)->transition(auth()->user(), $record, $status, $data['notes'] ?? null), $action));
        }
        $actions[] = Action::make('mark_duplicate')->requiresConfirmation()->schema([
            TextInput::make('duplicate_of_id')->numeric()->required()->label('Earlier existing candidate ID'),
            Textarea::make('notes')->maxLength(2000),
        ])->action(fn (NewsCandidate $record, array $data, Action $action): NewsCandidate => EditorialForm::saveForAction(fn (): NewsCandidate => app(CandidateReviewService::class)->transition(auth()->user(), $record, CandidateStatus::Duplicate, $data['notes'] ?? null, (int) $data['duplicate_of_id']), $action));

        return $table->columns([
            TextColumn::make('headline')->searchable()->wrap()->limit(120),
            TextColumn::make('language')->badge(), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('automation_status')->badge(), TextColumn::make('automation_error')->wrap(),
            TextColumn::make('topic')->sortable(), TextColumn::make('references_count')->label('Source references'),
            TextColumn::make('duplicate_of_id')->label('Possible duplicate of'),
            TextColumn::make('published_at')->dateTime()->sortable(), TextColumn::make('discovered_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(collect(CandidateStatus::cases())->mapWithKeys(fn (CandidateStatus $status): array => [$status->value => str_replace('_', ' ', ucfirst($status->value))])->all()),
            SelectFilter::make('topic')->options(NewsSourceResource::topicOptions()),
            SelectFilter::make('category_id')->label('Editorial category')->options(fn (): array => ArticleResource::categoryOptions()),
        ])->recordActions($actions)->defaultSort('discovered_at', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('references')
            ->with(['reviewer:id,name', 'references' => fn (Builder|Relation $query): Builder|Relation => $query->oldest('id')->limit(20), 'references.source']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewsCandidates::route('/')];
    }
}
