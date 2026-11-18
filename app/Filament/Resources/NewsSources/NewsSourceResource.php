<?php

namespace App\Filament\Resources\NewsSources;

use App\Filament\EditorialForm;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\NewsSources\Pages\ManageNewsSources;
use App\Models\NewsSource;
use App\Support\NewsDiscovery;
use App\Support\NewsSourceManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class NewsSourceResource extends Resource
{
    protected static ?string $model = NewsSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRss;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('website')->url()->required()->maxLength(2048)->helperText('Publisher identity, HTTPS only; separate from the feed endpoint.'),
            TextInput::make('feed_url')->url()->required()->maxLength(2048)->helperText('Official or explicitly permitted RSS/Atom endpoint; no credentials or redirect URLs.'),
            Select::make('source_type')->options(['rss' => 'RSS', 'atom' => 'Atom'])->required()->default('rss'),
            TextInput::make('language')->default('en')->required()->maxLength(35)->helperText('Original BCP 47 language tag, lowercase. No translation is performed.'),
            Select::make('category_id')->options(fn (): array => ArticleResource::categoryOptions())->searchable()->label('Existing editorial category'),
            Select::make('topic')->options(static::topicOptions())->searchable()->helperText('Source hint only; uncertain assignments still require review.'),
            TextInput::make('priority')->numeric()->minValue(1)->maxValue(5)->default(3)->required()->helperText('1 = highest review/poll priority; no automatic content approval.'),
            TextInput::make('polling_minutes')->numeric()->minValue(15)->maxValue(1440)->helperText('Blank uses the control-center default (60 minutes initially).'),
            TextInput::make('item_limit')->numeric()->minValue(1)->maxValue(100)->default(25)->required(),
            Textarea::make('licensing_notes')->required()->minLength(10)->maxLength(2000)->columnSpanFull()->helperText('Record publisher feed permission, attribution and usage restrictions. No full articles are imported.'),
            Toggle::make('summary_permitted')->default(false)->helperText('Store a plain-text snippet only when the documented usage rights allow it.'),
            Toggle::make('ai_usage_allowed')->default(false)->helperText('Separate permission for AI processing of verified factual evidence; document permitted use in licensing notes.'),
            Toggle::make('is_primary')->default(false)->helperText('Official first-party announcement, filing or regulator; not a secondary report.'),
            TextInput::make('publisher_group')->maxLength(100)->helperText('Shared lowercase ownership key, e.g. publisher-group. Different brands in one group are not independent corroboration.'),
            Toggle::make('approval_confirmed')->default(false)->afterStateHydrated(function (Toggle $component, ?NewsSource $record): void {
                $component->state((bool) $record?->approved_at);
            })->helperText('I verified this endpoint and its permitted usage. Approval is recorded against the current administrator.'),
            Toggle::make('enabled')->default(false),
        ]);
    }

    public static function topicOptions(): array
    {
        return collect(config('discovery.topics'))->mapWithKeys(fn (array $rules, string $key): array => [$key => $rules[0]])->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'), TextEntry::make('website'), TextEntry::make('feed_url'),
            TextEntry::make('licensing_notes')->columnSpanFull(), TextEntry::make('language'),
            TextEntry::make('ai_usage_allowed')->badge(), TextEntry::make('is_primary')->badge(), TextEntry::make('publisher_group'),
            TextEntry::make('approver.name')->label('Approved by'), TextEntry::make('approved_at')->dateTime(),
            TextEntry::make('last_fetched_at')->dateTime(), TextEntry::make('last_success_at')->dateTime(),
            TextEntry::make('last_error')->placeholder('No error'), TextEntry::make('failure_count'),
            TextEntry::make('next_poll_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(), TextColumn::make('source_type')->badge(),
            TextColumn::make('language')->badge(), TextColumn::make('topic')->sortable(),
            TextColumn::make('priority')->sortable(), TextColumn::make('enabled')->badge(),
            TextColumn::make('last_success_at')->dateTime()->sortable(),
            TextColumn::make('last_error')->placeholder('No error'),
            TextColumn::make('failure_count')->label('Consecutive failures'),
            TextColumn::make('next_poll_at')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('enabled'), SelectFilter::make('topic')->options(static::topicOptions())])
            ->recordActions([
                ViewAction::make(),
                Action::make('disable')->label('Disable discovery source')->requiresConfirmation()
                    ->visible(fn (NewsSource $record): bool => (bool) $record->enabled)
                    ->action(function (NewsSource $record): void {
                        app(NewsSourceManager::class)->disable(auth()->user(), $record);
                    }),
                EditAction::make()->using(fn (NewsSource $record, array $data, Action $action): NewsSource => EditorialForm::saveForAction(fn (): NewsSource => app(NewsSourceManager::class)->save(auth()->user(), $data, $record), $action)),
                Action::make('fetch')->label('Queue due fetch')->requiresConfirmation()->action(function (NewsSource $record): void {
                    Gate::authorize('fetch', $record);
                    $count = app(NewsDiscovery::class)->queueDue(auth()->user(), $record->id);
                    Notification::make()->title($count ? 'Source fetch queued' : 'No fetch queued: discovery disabled, cooling down or already queued')->send();
                }),
            ])->defaultSort('priority')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('approver:id,name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageNewsSources::route('/')];
    }
}
