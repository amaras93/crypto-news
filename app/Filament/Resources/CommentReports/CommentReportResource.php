<?php

namespace App\Filament\Resources\CommentReports;

use App\Filament\Resources\CommentReports\Pages\ManageCommentReports;
use App\Models\CommentReport;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class CommentReportResource extends Resource
{
    protected static ?string $model = CommentReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?int $navigationSort = 3;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('comment.body')->columnSpanFull(), TextEntry::make('comment.user.name')->label('Comment author'),
            TextEntry::make('comment.article_id'), TextEntry::make('comment.locale'), TextEntry::make('reason'),
            TextEntry::make('user.name')->label('Reporter'), TextEntry::make('resolved_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('comment.body')->limit(90)->searchable()->wrap(),
            TextColumn::make('comment.user.name')->label('Comment author'),
            TextColumn::make('comment.article_id')->label('Article'),
            TextColumn::make('comment.locale')->label('Language'),
            TextColumn::make('user.name')->label('Reporter')->searchable(),
            TextColumn::make('reason')->badge(),
            TextColumn::make('resolved_at')->dateTime()->placeholder('Open')->sortable(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->filters([
            TernaryFilter::make('resolved_at')->nullable()->label('Resolved'),
            SelectFilter::make('reason')->options(array_combine(CommentReport::REASONS, CommentReport::REASONS)),
        ])->recordActions([
            ViewAction::make(),
            Action::make('resolve')->visible(fn (CommentReport $record): bool => ! $record->resolved_at)
                ->requiresConfirmation()->action(fn (CommentReport $record) => app(CommunityComments::class)->resolveReport(auth()->user(), $record->id)),
            Action::make('mark_spam')->requiresConfirmation()
                ->action(fn (CommentReport $record) => app(CommunityComments::class)->moderate(auth()->user(), $record->comment_id, 'spam')),
        ])->toolbarActions([
            BulkAction::make('resolve_selected')->requiresConfirmation()->action(function (Collection $records): void {
                Gate::authorize('resolveAny', CommentReport::class);
                DB::transaction(function () use ($records): void {
                    foreach ($records as $record) {
                        app(CommunityComments::class)->resolveReport(auth()->user(), $record->id);
                    }
                });
            })->deselectRecordsAfterCompletion(),
        ])->defaultSort('created_at', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'comment.user:id,name']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommentReports::route('/')];
    }
}
