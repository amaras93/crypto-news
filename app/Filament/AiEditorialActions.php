<?php

namespace App\Filament;

use App\ArticleStatus;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use App\Support\AiFailure;
use App\Support\AiOutputSchema;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class AiEditorialActions
{
    public static function perform(Closure $operation, Action $action): mixed
    {
        return EditorialForm::saveForAction(function () use ($operation): mixed {
            try {
                $result = $operation();
                Notification::make()->title('AI editorial operation completed')->success()->send();

                return $result;
            } catch (AiFailure $failure) {
                throw ValidationException::withMessages(['ai' => $failure->reason]);
            }
        }, $action);
    }

    /** @return list<Action> */
    public static function articles(?Closure $before = null): array
    {
        $visible = fn (Article $record): bool => (bool) $record->ai_generation_run_id && auth()->user()->can('update', $record)
            && in_array($record->status, [ArticleStatus::Draft, ArticleStatus::PendingReview], true);

        return [
            Action::make('ai_review')->label('Verify / reject AI draft')->visible($visible)->requiresConfirmation()
                ->modalDescription('Review the saved text against its original sources. Candidate approval and AI screening are not independent fact verification.')
                ->schema([
                    Select::make('locale')->options(fn (Article $record): array => $record->fresh(['translations'])->translations->whereIn('locale', array_keys(config('news.locales')))->mapWithKeys(fn (ArticleTranslation $translation): array => [$translation->locale => config('news.locales.'.$translation->locale)])->all())->required()->default('en'),
                    Select::make('decision')->options(['approve' => 'Facts verified by me', 'reject' => 'Reject / request changes'])->required(),
                    Checkbox::make('confirmed')->label('I checked every claim, number, date, entity, source permission and attribution, and this translation against approved English.'),
                    Textarea::make('notes')->required()->minLength(30)->maxLength(2000),
                ])->action(function (Article $record, array $data, Action $action) use ($before): void {
                    if ($before) {
                        $before();
                    }
                    static::perform(fn () => app(AiEditorialReviewService::class)->review(auth()->user(), $record->fresh(), $data['locale'],
                        $data['decision'] === 'approve', (bool) ($data['confirmed'] ?? false), $data['notes']), $action);
                }),
            Action::make('ai_translate')->label('Generate / regenerate translations')->visible($visible)->requiresConfirmation()
                ->modalDescription('Saved English must have a current human fact review. Each language is an independent draft and request.')
                ->schema([
                    Select::make('locales')->multiple()->options(collect(config('news.locales'))->except('en')->all())->required(),
                    Checkbox::make('overwrite')->label('Explicitly replace existing human-edited content in the selected languages.'),
                ])->action(function (Article $record, array $data, Action $action) use ($before): void {
                    if ($before) {
                        $before();
                    }
                    static::perform(fn () => app(AiDrafting::class)->translate(auth()->user(), $record->fresh(), $data['locales'], (bool) ($data['overwrite'] ?? false)), $action);
                }),
            Action::make('ai_section')->label('Propose replacement section')->visible($visible)->requiresConfirmation()
                ->modalDescription('Creates a private proposal in Generation Runs. Applying it requires separate confirmation and invalidates content reviews.')
                ->schema([Select::make('section')->options(array_combine(AiOutputSchema::SECTIONS, AiOutputSchema::SECTIONS))->required()])
                ->action(function (Article $record, array $data, Action $action) use ($before): void {
                    if ($before) {
                        $before();
                    }
                    static::perform(fn () => app(AiDrafting::class)->section(auth()->user(), $record->fresh(), $data['section']), $action);
                }),
        ];
    }
}
