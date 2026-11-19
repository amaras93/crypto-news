<?php

namespace App\Filament\Resources\CandidateEvidence;

use App\Filament\AiEditorialActions;
use App\Filament\Resources\CandidateEvidence\Pages\ManageCandidateEvidence;
use App\Models\CandidateEvidence;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use App\Support\AiEvidence;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CandidateEvidenceResource extends Resource
{
    protected static ?string $model = CandidateEvidence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Editorial';

    protected static ?string $navigationLabel = 'Verified evidence';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('news_candidate_id')->label('Candidate')->options(fn (): array => NewsCandidate::latest('id')->limit(100)->pluck('headline', 'id')->all())
                ->searchable()->required()->live()->disabled(fn (?CandidateEvidence $record): bool => (bool) $record)->dehydrated(),
            Textarea::make('claim')->required()->minLength(60)->maxLength(1500)->helperText('Write the factual claim in your own words after inspecting the original permitted source. A headline is not sufficient evidence.'),
            Select::make('reference_ids')->multiple()->required()->options(fn (Get $get): array => CandidateSourceReference::where('news_candidate_id', $get('news_candidate_id'))->with('source:id,name')->get()->mapWithKeys(fn (CandidateSourceReference $reference): array => [$reference->id => $reference->source->name.' · '.$reference->original_url])->all()),
            TagsInput::make('entities')->required()->helperText('Exact company names and asset symbols supported by this claim.'),
            TagsInput::make('figures')->helperText('Exact supported numerical strings, including currencies, percentages and ISO dates.'),
            Toggle::make('material')->default(true)->helperText('Material claims need two independent publisher groups or an official primary source.'),
            Textarea::make('review_notes')->required()->minLength(20)->maxLength(2000),
            Checkbox::make('confirmed')->required()->accepted()->label('I inspected the original sources, their timestamps and permissions and verified this claim.'),
        ]);
    }

    public static function persist(array $data, Action $action, ?CandidateEvidence $record = null): CandidateEvidence
    {
        return AiEditorialActions::perform(fn (): CandidateEvidence => app(AiEvidence::class)->save(auth()->user(),
            NewsCandidate::findOrFail($data['news_candidate_id']), $data + ['figures' => []], $record), $action);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('candidate.headline'), TextEntry::make('claim')->columnSpanFull(),
            TextEntry::make('reference_ids')->listWithLineBreaks(), TextEntry::make('entities')->listWithLineBreaks(),
            TextEntry::make('figures')->listWithLineBreaks(), TextEntry::make('review_notes')->columnSpanFull(),
            TextEntry::make('verifier.name'), TextEntry::make('verified_at')->dateTime()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('candidate.headline')->searchable()->limit(70)->wrap(),
            TextColumn::make('claim')->limit(100)->wrap(), TextColumn::make('verification_method')->badge(),
            TextColumn::make('automated_verified_at')->dateTime(), TextColumn::make('verifier.name'), TextColumn::make('verified_at')->dateTime()])
            ->recordActions([ViewAction::make(), EditAction::make()->using(fn (CandidateEvidence $record, array $data, Action $action): CandidateEvidence => static::persist($data, $action, $record))])
            ->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['candidate', 'verifier']);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCandidateEvidence::route('/')];
    }
}
