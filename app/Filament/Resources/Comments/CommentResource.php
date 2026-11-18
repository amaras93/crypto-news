<?php

namespace App\Filament\Resources\Comments;

use App\CommentStatus;
use App\Filament\Resources\Comments\Pages\ManageComments;
use App\Models\Comment;
use App\Models\CommentModerationEvent;
use App\Support\CommunityComments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('body')->columnSpanFull(),
            TextEntry::make('user.name')->label('Comment author'),
            TextEntry::make('article_id')->label('Article ID'),
            TextEntry::make('locale'), TextEntry::make('status')->badge(), TextEntry::make('created_at')->dateTime(),
            TextEntry::make('history')->state(fn (Comment $record): string => $record->moderationEvents()->with('user:id,name')->latest('id')->limit(20)->get()
                ->map(fn (CommentModerationEvent $event): string => $event->created_at->toDateTimeString().' · '.$event->action.' · '.($event->user?->name ?? 'Deleted user'))->implode("\n"))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $actions = [ViewAction::make()];
        foreach (['approve', 'reject', 'spam', 'remove', 'restore'] as $action) {
            $actions[] = Action::make($action)->label(ucfirst($action))->requiresConfirmation()
                ->visible(fn (Comment $record): bool => $action === 'restore' ? $record->trashed() : ! $record->trashed())
                ->action(fn (Comment $record): Comment => app(CommunityComments::class)->moderate(auth()->user(), $record->id, $action));
        }
        $bulk = [];
        foreach (['approve', 'reject', 'spam', 'remove', 'restore'] as $action) {
            $bulk[] = BulkAction::make($action.'_selected')->label(ucfirst($action).' selected')->requiresConfirmation()
                ->visible(fn (): bool => (bool) auth()->user()?->is_admin)
                ->action(function (Collection $records) use ($action): void {
                    Gate::authorize('moderateAny', Comment::class);
                    DB::transaction(function () use ($records, $action): void {
                        foreach ($records as $record) {
                            if (($action === 'restore') === $record->trashed()) {
                                app(CommunityComments::class)->moderate(auth()->user(), $record->id, $action);
                            }
                        }
                    });
                })->deselectRecordsAfterCompletion();
        }

        return $table->columns([
            TextColumn::make('body')->limit(90)->searchable()->wrap(),
            TextColumn::make('user.name')->label('Reader')->searchable(),
            TextColumn::make('article_headline')->state(fn (Comment $record): string => $record->article?->translation($record->locale)?->title ?? 'Unavailable article')
                ->url(fn (Comment $record): ?string => $record->article ? route('filament.admin.resources.articles.edit', ['record' => $record->article_id]) : null),
            TextColumn::make('locale')->badge(), TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('parent_id')->label('Parent')->placeholder('Top level'),
            TextColumn::make('active_reports_count')->label('Open reports')->sortable(),
            TextColumn::make('likes_count')->label('Likes')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->options(collect(CommentStatus::cases())->mapWithKeys(fn (CommentStatus $status): array => [$status->value => ucfirst($status->value)])->all()),
            SelectFilter::make('locale')->options(config('news.locales')),
            SelectFilter::make('article_id')->label('Article')->relationship('article', 'id')->searchable(),
            SelectFilter::make('user_id')->label('Reader')->relationship('user', 'name')->searchable(),
            TernaryFilter::make('reported')->label('Open reports')
                ->queries(true: fn (Builder $query): Builder => $query->whereHas('reports', fn (Builder $reports): Builder => $reports->whereNull('resolved_at')),
                    false: fn (Builder $query): Builder => $query->whereDoesntHave('reports', fn (Builder $reports): Builder => $reports->whereNull('resolved_at'))),
            TrashedFilter::make(),
        ])->recordActions($actions)->toolbarActions($bulk)->defaultSort('created_at', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withTrashed()->with(['user:id,name', 'article.translations:id,article_id,locale,title,slug'])
            ->withCount(['likes', 'reports as active_reports_count' => fn (Builder $query): Builder => $query->whereNull('resolved_at')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageComments::route('/')];
    }
}
