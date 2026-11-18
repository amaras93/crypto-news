<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (config('news.locales') as $locale => $name) {
            $tabs[] = Tab::make($name)->statePath('translations.'.$locale)->schema([
                Toggle::make('enabled')->label('Add / edit translation')->default($locale === 'en')->live(),
                Section::make('Category translation')->visible(fn (Get $get): bool => (bool) $get('enabled'))->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('slug')->required()->maxLength(190)->readOnly(fn (?Category $record): bool => (bool) $record?->translation($locale))
                        ->helperText('Existing topic slugs are locked to preserve navigation.'),
                    Textarea::make('description')->maxLength(2000),
                    TextInput::make('meta_title')->maxLength(255),
                    Textarea::make('meta_description')->maxLength(2000),
                ]),
            ]);
        }

        return $schema->components([
            TextInput::make('sort_order')->numeric()->minValue(0)->maxValue(100000)->default(0)->required(),
            Tabs::make('Translations')->tabs($tabs)->columnSpanFull(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->getStateUsing(fn (Category $record): string => $record->translation('en')?->name ?? $record->translations->first()?->name ?? 'Category #'.$record->id)
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('translations', fn (Builder $q): Builder => $q->where('name', 'like', '%'.$search.'%'))),
            TextColumn::make('sort_order')->sortable(),
            TextColumn::make('articles_count')->counts('articles')->label('Articles'),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->defaultSort('sort_order')->recordActions([EditAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('translations');
    }

    public static function getPages(): array
    {
        return ['index' => ListCategories::route('/'), 'create' => CreateCategory::route('/create'), 'edit' => EditCategory::route('/{record}/edit')];
    }
}
