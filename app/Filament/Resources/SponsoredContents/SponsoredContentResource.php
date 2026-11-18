<?php

namespace App\Filament\Resources\SponsoredContents;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\SponsoredContents\Pages\ManageSponsoredContents;
use App\Models\Article;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SponsoredContentResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Advertising';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->fresh()?->is_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('headline')->state(fn (Article $record): string => ArticleResource::headline($record)),
            TextColumn::make('sponsor.public_brand_name'), TextColumn::make('sponsor_classification'), TextColumn::make('status')->badge(),
            TextColumn::make('sponsorship_reviewed_at')->dateTime()])
            ->recordActions([Action::make('editorial_workflow')->label('Open existing editorial workflow')
                ->url(fn (Article $record): string => ArticleResource::getUrl('edit', ['record' => $record]))])
            ->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('sponsor_classification')->with(['translations', 'sponsor']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSponsoredContents::route('/')];
    }
}
