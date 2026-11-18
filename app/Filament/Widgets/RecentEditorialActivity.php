<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\EditorialEvent;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentEditorialActivity extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Recent editorial activity')->query(EditorialEvent::query()
            ->when(! auth()->user()->is_admin, fn (Builder $query): Builder => $query->whereHas('article', fn (Builder $article): Builder => $article->where('author_id', auth()->id())))
            ->with(['article.translations:id,article_id,locale,title', 'user:id,name']))
            ->columns([
                TextColumn::make('article')->getStateUsing(fn (EditorialEvent $record): string => $record->article ? ArticleResource::headline($record->article) : 'Removed article')->wrap(),
                TextColumn::make('action')->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state))),
                TextColumn::make('user.name')->placeholder('System'),
                TextColumn::make('created_at')->dateTime(),
            ])->defaultSort('id', 'desc')->paginated([10])->defaultPaginationPageOption(10);
    }
}
