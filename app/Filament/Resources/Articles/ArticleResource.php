<?php

namespace App\Filament\Resources\Articles;

use App\ArticleStatus;
use App\Filament\AdvertisingForms;
use App\Filament\AiEditorialActions;
use App\Filament\EditorialForm;
use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Filament\Resources\Articles\Pages\EditArticle;
use App\Filament\Resources\Articles\Pages\ListArticles;
use App\Models\Advertiser;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\EditorialSetting;
use App\Models\Tag;
use App\Models\User;
use App\Support\ArticleMedia;
use App\Support\ArticlePublication;
use App\Support\EditorialMedia;
use App\Support\SponsoredContentService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function headline(Article $record): string
    {
        return $record->translation('en')?->title
            ?? $record->translations->first(fn (ArticleTranslation $translation): bool => array_key_exists($translation->locale, config('news.locales')))?->title
            ?? 'Article #'.$record->id;
    }

    /** @return array<int, string> */
    public static function categoryOptions(): array
    {
        return Category::with('translations')->orderBy('sort_order')->get()->mapWithKeys(
            fn (Category $category): array => [$category->id => $category->translation('en')?->name ?? $category->translations->first()?->name ?? 'Category #'.$category->id],
        )->all();
    }

    public static function form(Schema $schema): Schema
    {
        $tabs = [];
        foreach (config('news.locales') as $locale => $name) {
            $tabs[] = Tab::make($name)->schema([
                Toggle::make('enabled')->label('Add / edit this translation')->default($locale === 'en')->live()
                    ->helperText('Partial coverage is allowed. Existing translations are retained when a tab is left off.'),
                Section::make('Localized content')->visible(fn (Get $get): bool => (bool) $get('enabled'))->schema([
                    TextInput::make('title')->label('Headline')->required()->maxLength(255),
                    TextInput::make('slug')->required()->maxLength(190)
                        ->readOnly(fn (?Article $record): bool => (bool) $record?->published_at)
                        ->helperText('New slugs use lowercase letters, numbers, hyphens or underscores. Existing publication URLs are locked.'),
                    Textarea::make('excerpt')->label('Short description')->maxLength(2000)->rows(3),
                    RichEditor::make('body')->required()->maxLength(500000)->columnSpanFull()
                        ->toolbarButtons([['bold', 'italic', 'link'], ['h2', 'h3'], ['blockquote', 'bulletList', 'orderedList'], ['attachFiles', 'table'], ['undo', 'redo']])
                        ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('articles/body')->fileAttachmentsVisibility('public')
                        ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->fileAttachmentsMaxSize(5120)
                        ->saveUploadedFileAttachmentUsing(fn (UploadedFile $file): string => app(EditorialMedia::class)->store(auth()->user(), $file, 'body')),
                    Textarea::make('summary_text')->label('Optional summary')->rows(4)->maxLength(2500)
                        ->helperText('Enter 3–5 short takeaways, one per line. Leave blank for no summary.'),
                    Select::make('summary_source')->options(['manual' => 'Human editorial', 'ai' => 'AI provenance (existing / imported)'])->default('manual'),
                    TextInput::make('image_alt')->label('Featured image alt text')->maxLength(255),
                    TextInput::make('meta_title')->label('SEO title')->maxLength(255),
                    Textarea::make('meta_description')->label('SEO description')->maxLength(2000)->rows(3),
                ])->columns(1),
            ])->statePath('translations.'.$locale);
        }

        return $schema->components([
            Section::make('AI-assisted editorial provenance')->visible(fn (?Article $record): bool => (bool) $record?->ai_generation_run_id)->schema([
                TextEntry::make('ai_provenance')->state(function (Article $record): string {
                    $run = $record->aiRun;

                    return 'AI-assisted draft · '.$run?->provider.' / '.$run?->model.' · run #'.$run?->id.' · candidate #'.$record->ai_candidate_id.' · generated '.$run?->completed_at?->toIso8601String().($record->ai_simulated ? ' · SIMULATED / NEVER PUBLIC' : '');
                })->columnSpanFull(),
                TextEntry::make('ai_translation_review')->state(fn (Article $record): array => $record->fresh(['translations'])->translations->map(fn (ArticleTranslation $translation): string => strtoupper($translation->locale).': '.($translation->ai_review_status ?? 'legacy').' · reviewer '.($translation->ai_reviewed_by ?? 'none').' · '.($translation->ai_reviewed_at?->toIso8601String() ?? 'not reviewed'))->all())->listWithLineBreaks()->columnSpanFull(),
                TextEntry::make('ai_source_references')->state(fn (Article $record): array => collect($record->aiRun?->evidence_snapshot['claims'] ?? [])->flatMap(fn (array $claim): array => array_map(fn (array $reference): string => $reference['publisher'].' · '.$reference['url'].' · source '.$reference['source_timestamp'].' · retrieved '.$reference['retrieved_at'], $claim['references']))->unique()->values()->all())->listWithLineBreaks()->columnSpanFull(),
                TextEntry::make('ai_quality')->state(fn (Article $record): string => json_encode($record->aiRun?->quality_report ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))->columnSpanFull(),
            ]),
            Section::make('Editorial details')->schema([
                Select::make('author_id')->label('Author')->required()->searchable()
                    ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->user()->is_admin ? (EditorialSetting::find(1)?->default_author_id ?? auth()->id()) : auth()->id())
                    ->disabled(fn (): bool => ! auth()->user()->is_admin)->dehydrated(),
                Select::make('category_id')->label('Category')->searchable()->options(fn (): array => static::categoryOptions())
                    ->default(fn (): ?int => EditorialSetting::find(1)?->default_category_id),
                Select::make('tag_ids')->label('Tags')->multiple()->searchable()->options(fn (): array => Tag::orderBy('name')->pluck('name', 'id')->all()),
            ])->columns(3),
            Section::make('Featured image')->description('JPEG, PNG or WebP; at most 5 MB and 6000 × 6000 pixels. Replacing a reference never deletes a shared file.')->schema([
                Hidden::make('featured_image'),
                ImageEntry::make('current_image')->label('Current featured image')->state(fn (?Article $record): ?string => $record ? app(ArticleMedia::class)->resolve($record->featured_image) : null)
                    ->visible(fn (?Article $record): bool => filled($record?->featured_image))->imageSize(200),
                FileUpload::make('featured_image_upload')->label('Upload / replace image')->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(5120)
                    ->disk('public')->directory('articles/featured')->visibility('public')
                    ->saveUploadedFileUsing(fn (UploadedFile $file): string => app(EditorialMedia::class)->store(auth()->user(), $file)),
                Select::make('existing_featured_image')->label('Or reuse an uploaded image')->searchable()
                    ->options(fn (): array => app(EditorialMedia::class)->existing())
                    ->helperText('An existing selection takes precedence over an upload. Current legacy images are preserved.'),
                TextInput::make('image_credit')->label('Image credit / attribution')->maxLength(500),
            ])->columns(2),
            Tabs::make('Translations')->tabs($tabs)->columnSpanFull()->persistTabInQueryString(),
            Section::make('Sponsorship and advertising disclosure')->visible(fn (): bool => (bool) auth()->user()?->is_admin)->statePath('sponsorship')->schema([
                Select::make('classification')->options(['sponsored' => 'Sponsored content', 'advertisement' => 'Advertisement'])->placeholder('Independent editorial article'),
                Select::make('sponsor_id')->label('Actual sponsor')->options(fn (): array => Advertiser::orderBy('public_brand_name')->pluck('public_brand_name', 'id')->all()),
                ...collect(config('news.locales'))->map(fn (string $name, string $locale) => Textarea::make('disclosures.'.$locale)->label('Accurate sponsorship disclosure — '.$name)->maxLength(2000)->rows(3))->values()->all(),
            ])->description('Classification is administrator-only. Paid content requires a saved disclosure in every available language and a separate commercial review within the existing editorial approval workflow. Changes return the article to draft.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('featured_image')->label('Image')->getStateUsing(fn (Article $record): ?string => app(ArticleMedia::class)->resolve($record->featured_image))->imageSize(48),
            TextColumn::make('headline')->getStateUsing(fn (Article $record): string => static::headline($record))->wrap()->limit(70)
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('translations', fn (Builder $q): Builder => $q->whereIn('locale', array_keys(config('news.locales')))->where('title', 'like', '%'.$search.'%'))),
            TextColumn::make('author.name')->sortable()->searchable(),
            TextColumn::make('category')->getStateUsing(fn (Article $record): string => $record->category?->translation('en')?->name ?? $record->category?->translations->first()?->name ?? '—'),
            TextColumn::make('status')->badge()->sortable()->formatStateUsing(fn (ArticleStatus $state): string => ucwords(str_replace('_', ' ', $state->value))),
            TextColumn::make('approved_at')->label('Approval')->getStateUsing(fn (Article $record): string => $record->approved_at ? 'Approved' : 'Not approved')->toggleable(),
            TextColumn::make('languages')->getStateUsing(fn (Article $record): array => $record->translations->pluck('locale')->filter(fn (string $locale): bool => array_key_exists($locale, config('news.locales')))->map(fn (string $locale): string => strtoupper($locale))->all())->badge(),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('published_at')->dateTime()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->defaultSort('updated_at', 'desc')->paginated([10, 25, 50])->filters([
            SelectFilter::make('status')->options(collect(ArticleStatus::cases())->mapWithKeys(fn (ArticleStatus $status): array => [$status->value => ucwords(str_replace('_', ' ', $status->value))])->all()),
            SelectFilter::make('category_id')->label('Category')->options(fn (): array => static::categoryOptions()),
            SelectFilter::make('author_id')->label('Author')->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('language')->options(config('news.locales'))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $locale): Builder => $q->whereHas('translations', fn (Builder $t): Builder => $t->where('locale', $locale)))),
            Filter::make('publication_date')->schema([DateTimePicker::make('from')->timezone('UTC'), DateTimePicker::make('until')->timezone('UTC')])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->where('published_at', '>=', $date))->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->where('published_at', '<=', $date))),
            TrashedFilter::make()->visible(fn (): bool => (bool) auth()->user()->is_admin),
        ])->recordActions([
            EditAction::make(),
            ...static::workflowActions(),
            ...AiEditorialActions::articles(),
            Action::make('trash')->label('Move to trash')->color('danger')->requiresConfirmation()->authorize('delete')
                ->action(fn (Article $record) => app(ArticlePublication::class)->trash(auth()->user(), $record)),
            Action::make('restore')->requiresConfirmation()->authorize('restore')
                ->action(fn (Article $record) => app(ArticlePublication::class)->restore(auth()->user(), $record)),
        ])->toolbarActions([
            BulkAction::make('trash_selected')->label('Move selected to trash')->color('danger')->requiresConfirmation()
                ->visible(fn (): bool => (bool) auth()->user()->is_admin)
                ->action(function (Collection $records): void {
                    Gate::authorize('deleteAny', Article::class);
                    DB::transaction(function () use ($records): void {
                        foreach ($records as $record) {
                            app(ArticlePublication::class)->trash(auth()->user(), $record);
                        }
                    });
                })->deselectRecordsAfterCompletion(),
        ]);
    }

    /** @return array<Action> */
    public static function workflowActions(?Closure $before = null): array
    {
        $actions = [];
        foreach (['save_draft' => 'Save Draft', 'submit_review' => 'Submit for Review', 'approve' => 'Approve', 'schedule' => 'Schedule', 'publish' => 'Publish', 'unpublish' => 'Unpublish'] as $operation => $label) {
            $actions[] = Action::make($operation)->label($label)->requiresConfirmation()
                ->modalDescription('This operation applies to the saved article. On the edit page, valid form changes are saved first.')
                ->visible(function (Article $record) use ($operation): bool {
                    if ($record->trashed()) {
                        return false;
                    }
                    if (in_array($operation, ['save_draft', 'submit_review'], true)) {
                        return auth()->user()->can('submit', $record);
                    }
                    if (! auth()->user()->can('publish', $record)) {
                        return false;
                    }

                    return match ($operation) {
                        'approve' => $record->status === ArticleStatus::PendingReview,
                        'unpublish' => in_array($record->status, [ArticleStatus::Published, ArticleStatus::Scheduled], true),
                        default => $record->status !== ArticleStatus::Published || ! $record->published_at || $record->published_at->isFuture(),
                    };
                })
                ->schema($operation === 'schedule' ? [DateTimePicker::make('published_at')->label('Publication time (UTC)')->timezone('UTC')->required()->after('now')] : [])
                ->action(function (Article $record, array $data) use ($operation, $before): void {
                    if ($before) {
                        $before();
                    }
                    $updated = app(ArticlePublication::class)->transition(auth()->user(), $record, $operation,
                        isset($data['published_at']) ? CarbonImmutable::parse($data['published_at'], 'UTC') : null);
                    $record->setRawAttributes($updated->getAttributes(), true);
                    $record->setRelations($updated->getRelations());
                    Notification::make()->title('Editorial operation completed')->success()->send();
                });
        }
        $actions[] = Action::make('preview')->label('Preview')->authorize('preview')
            ->url(fn (Article $record): string => route('editorial.preview', ['article' => $record, 'locale' => $record->translations->first(fn (ArticleTranslation $translation): bool => array_key_exists($translation->locale, config('news.locales')))?->locale ?? 'en']))
            ->openUrlInNewTab();

        $actions[] = Action::make('review_sponsorship')->label('Review saved sponsorship')->schema(AdvertisingForms::review())->requiresConfirmation()
            ->visible(fn (Article $record): bool => (bool) auth()->user()?->is_admin && $record->isSponsored() && $record->status === ArticleStatus::PendingReview)
            ->modalDescription('Reviews the saved article and disclosures. Save changes and submit for review first. This does not publish the article; the existing editorial approval and publishing actions still apply.')
            ->action(fn (Article $record, array $data, Action $action) => EditorialForm::saveForAction(fn () => app(SponsoredContentService::class)->review(auth()->user(), $record, $data), $action));

        return $actions;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['translations:id,article_id,locale,title,slug', 'author:id,name', 'category.translations:id,category_id,locale,name,slug', 'tags:id,name,slug']);
        if (! auth()->user()?->is_admin) {
            $query->where('author_id', auth()->id())->whereNull('deleted_at');
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->when(auth()->user()?->is_admin, fn (Builder $query): Builder => $query->withTrashed());
    }

    public static function getPages(): array
    {
        return ['index' => ListArticles::route('/'), 'create' => CreateArticle::route('/create'), 'edit' => EditArticle::route('/{record}/edit')];
    }
}
