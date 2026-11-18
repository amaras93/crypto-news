<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use App\Models\ArticleTranslation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestPublishedArticles extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table->heading('Latest published articles')->query(Article::query()->published()
            ->when(! auth()->user()->is_admin, fn (Builder $query): Builder => $query->where('author_id', auth()->id()))
            ->with(['translations:id,article_id,locale,title', 'author:id,name']))
            ->columns([
                TextColumn::make('headline')->getStateUsing(fn (Article $record): string => ArticleResource::headline($record))->wrap(),
                TextColumn::make('author.name'),
                TextColumn::make('published_at')->dateTime(),
            ])->defaultSort('published_at', 'desc')->paginated([5])->defaultPaginationPageOption(5)
            ->recordUrl(fn (Article $record): string => route('editorial.preview', ['article' => $record, 'locale' => $record->translations->first(fn (ArticleTranslation $translation): bool => array_key_exists($translation->locale, config('news.locales')))?->locale ?? 'en']));
    }
}
