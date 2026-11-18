<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Support\CommunityComments;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->maxLength(255),
            Select::make('role')->options(['administrator' => 'Administrator', 'editor' => 'Editor', 'reader' => 'Reader (no CMS access)'])->default('editor')->required(),
            TextInput::make('password')->password()->revealable()->maxLength(64)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->rule(Password::min(12)->mixedCase()->numbers()->symbols())
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText('12–64 characters, mixed case, number and symbol. Leave blank on edit to retain the password.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('email')->searchable()->sortable(),
            TextColumn::make('role')->getStateUsing(fn (User $record): string => $record->is_admin ? 'Administrator' : ($record->is_editor ? 'Editor' : 'Reader'))->badge(),
            TextColumn::make('created_at')->dateTime()->sortable(),
            TextColumn::make('community_suspended_at')->label('Community suspended')->dateTime()->placeholder('Active'),
        ])->recordActions([
            EditAction::make(),
            Action::make('suspend_community')->label('Suspend community access')->requiresConfirmation()
                ->visible(fn (User $record): bool => ! $record->community_suspended_at && $record->id !== auth()->id())
                ->action(fn (User $record) => app(CommunityComments::class)->suspend(auth()->user(), $record, true)),
            Action::make('restore_community')->label('Restore community access')->requiresConfirmation()
                ->visible(fn (User $record): bool => (bool) $record->community_suspended_at)
                ->action(fn (User $record) => app(CommunityComments::class)->suspend(auth()->user(), $record, false)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'create' => CreateUser::route('/create'), 'edit' => EditUser::route('/{record}/edit')];
    }
}
