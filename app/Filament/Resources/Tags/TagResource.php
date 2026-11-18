<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Tag;
use App\Support\EditorialTaxonomy;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(190)->unique(ignoreRecord: true)->readOnly(fn (?Tag $record): bool => $record !== null)
                ->helperText('Lowercase letters/numbers and hyphens. Existing slugs are locked.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable()->sortable(),
            TextColumn::make('articles_count')->counts('articles'),
        ])->recordActions([
            EditAction::make()->using(fn (Tag $record, array $data): Tag => app(EditorialTaxonomy::class)->tag(auth()->user(), $data, $record)),
            DeleteAction::make()->using(function (Tag $record): bool {
                app(EditorialTaxonomy::class)->delete(auth()->user(), $record);

                return true;
            }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTags::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount([
            'articles as protected_articles_count' => fn (Builder $query): Builder => $query->withTrashed(),
        ]);
    }
}
