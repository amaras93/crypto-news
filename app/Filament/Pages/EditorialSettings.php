<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\EditorialSetting;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use UnitEnum;

class EditorialSettings extends Page
{
    protected string $view = 'filament.pages.editorial-settings';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(EditorialSetting::find(1)?->only(['default_author_id', 'default_category_id']) ?? []);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('default_author_id')->label('Default author for administrator drafts')->searchable()
                ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())->rules(['nullable', 'exists:users,id']),
            Select::make('default_category_id')->label('Default category for new drafts')->searchable()
                ->options(fn (): array => ArticleResource::categoryOptions())->rules(['nullable', 'exists:categories,id']),
        ]);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->fresh()?->is_admin, 403);
        EditorialSetting::updateOrCreate(['id' => 1], Arr::only($this->form->getState(), ['default_author_id', 'default_category_id']));
        Notification::make()->title('Editorial defaults saved')->success()->send();
    }
}
