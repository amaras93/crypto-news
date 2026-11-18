<?php

namespace App\Filament\Resources\AiGenerationRuns;

use App\AiRunStatus;
use App\Filament\AiEditorialActions;
use App\Filament\Resources\AiGenerationRuns\Pages\ManageAiGenerationRuns;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\AiGenerationRun;
use App\Support\AiDrafting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use UnitEnum;

class AiGenerationRunResource extends Resource
{
    protected static ?string $model = AiGenerationRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 9;

    public static function infolist(Schema $schema): Schema
    {
        $json = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return $schema->components([
            TextEntry::make('id'), TextEntry::make('candidate.headline'), TextEntry::make('task'), TextEntry::make('locale'),
            TextEntry::make('provider'), TextEntry::make('model'), TextEntry::make('status')->badge(),
            TextEntry::make('is_simulated')->label('SIMULATED usage / never billable')->badge(),
            TextEntry::make('requester.name'), TextEntry::make('author.name'), TextEntry::make('billingUser.name')->label('Authorizing administrator'),
            TextEntry::make('attempt'), TextEntry::make('request_count'), TextEntry::make('provider_response_id'),
            TextEntry::make('estimated_microusd'), TextEntry::make('actual_microusd'),
            TextEntry::make('input_tokens'), TextEntry::make('cached_input_tokens'), TextEntry::make('cache_write_tokens'), TextEntry::make('output_tokens'),
            TextEntry::make('simulated_usage_display')->state(fn (AiGenerationRun $record): string => $json($record->simulated_usage)),
            TextEntry::make('quality_display')->state(fn (AiGenerationRun $record): string => $json($record->quality_report))->columnSpanFull(),
            TextEntry::make('evidence_display')->state(fn (AiGenerationRun $record): string => $json($record->evidence_snapshot))->columnSpanFull(),
            TextEntry::make('output_display')->state(fn (AiGenerationRun $record): string => $json($record->output_document))->columnSpanFull(),
            TextEntry::make('pricing_display')->state(fn (AiGenerationRun $record): string => $json($record->pricing_snapshot))->columnSpanFull(),
            TextEntry::make('error_code'), TextEntry::make('retry_at')->dateTime(), TextEntry::make('completed_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable()->searchable(), TextColumn::make('candidate.headline')->limit(70)->wrap()->searchable(),
            TextColumn::make('task'), TextColumn::make('locale')->badge(), TextColumn::make('status')->badge(),
            TextColumn::make('provider'), TextColumn::make('model'), TextColumn::make('is_simulated')->badge(),
            TextColumn::make('actual_microusd'), TextColumn::make('error_code')->wrap(), TextColumn::make('created_at')->dateTime(),
        ])->filters([SelectFilter::make('status')->options(collect(AiRunStatus::cases())->mapWithKeys(fn (AiRunStatus $status): array => [$status->value => $status->value])->all())])
            ->recordActions([
                ViewAction::make(),
                Action::make('article')->label('View / edit draft')->visible(fn (AiGenerationRun $record): bool => (bool) $record->article_id && (bool) $record->article)
                    ->url(fn (AiGenerationRun $record): string => ArticleResource::getUrl('edit', ['record' => $record->article_id])),
                Action::make('resume')->label('Resume saved stage')->requiresConfirmation()
                    ->visible(fn (AiGenerationRun $record): bool => (bool) auth()->user()->is_admin && in_array($record->status, [AiRunStatus::Received, AiRunStatus::Validated], true))
                    ->action(fn (AiGenerationRun $record, Action $action) => AiEditorialActions::perform(fn () => app(AiDrafting::class)->dispatch($record), $action)),
                Action::make('retry')->requiresConfirmation()->visible(fn (AiGenerationRun $record): bool => (bool) auth()->user()->is_admin && $record->status === AiRunStatus::Failed && (bool) $record->retry_at)
                    ->action(fn (AiGenerationRun $record, Action $action) => AiEditorialActions::perform(fn () => app(AiDrafting::class)->retry(auth()->user(), $record), $action)),
                Action::make('apply_section')->label('Apply reviewed section proposal')->requiresConfirmation()
                    ->visible(fn (AiGenerationRun $record): bool => $record->task === 'section' && $record->status === AiRunStatus::Stored && ! $record->section_applied_at && $record->article && auth()->user()->can('update', $record->article))
                    ->schema([Checkbox::make('confirmed')->accepted()->required()->label('I reviewed the proposal and explicitly authorize replacing this section. Existing reviews will be invalidated.')])
                    ->action(fn (AiGenerationRun $record, array $data, Action $action) => AiEditorialActions::perform(fn () => app(AiDrafting::class)->applySection(auth()->user(), $record, (bool) $data['confirmed']), $action)),
                Action::make('reconcile')->label('Reconcile provider usage')->requiresConfirmation()
                    ->visible(fn (AiGenerationRun $record): bool => (bool) auth()->user()->is_admin && $record->status === AiRunStatus::Uncertain)
                    ->modalDescription('Inspect provider billing records first. Unknown does not mean free. Enter verified total input (including cached/write), cached, cache-write and output usage. Reconciliation never retries generation.')
                    ->schema([TextInput::make('input_tokens')->integer()->minValue(0)->maxValue(1000000)->required(),
                        TextInput::make('cached_input_tokens')->integer()->minValue(0)->maxValue(1000000)->required(),
                        TextInput::make('cache_write_tokens')->integer()->minValue(0)->maxValue(1000000)->required(),
                        TextInput::make('output_tokens')->integer()->minValue(0)->maxValue(1000000)->required(),
                        Textarea::make('notes')->required()->minLength(30)->maxLength(1000),
                        Checkbox::make('confirmed')->accepted()->required()->label('Verified against provider records; zero usage is confirmed, not assumed.')])
                    ->action(fn (AiGenerationRun $record, array $data, Action $action) => AiEditorialActions::perform(fn () => app(AiDrafting::class)->reconcile(auth()->user(), $record,
                        array_map('intval', Arr::only($data, ['input_tokens', 'cached_input_tokens', 'cache_write_tokens', 'output_tokens'])), $data['notes']), $action)),
            ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['candidate', 'article', 'requester', 'author', 'billingUser'])
            ->when(! auth()->user()?->is_admin, fn (Builder $query): Builder => $query->where('author_id', auth()->id()));
    }

    public static function getPages(): array
    {
        return ['index' => ManageAiGenerationRuns::route('/')];
    }
}
