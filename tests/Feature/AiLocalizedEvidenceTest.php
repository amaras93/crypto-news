<?php

namespace Tests\Feature;

use App\Models\AiGenerationRun;
use App\Support\AiOutputSchema;
use App\Support\AiQuality;
use App\Support\AiSemanticVerification;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AiLocalizedEvidenceTest extends AiTestCase
{
    private function translatedFixture(): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-translation.json')), true, flags: JSON_THROW_ON_ERROR);
        $run = new AiGenerationRun(['task' => 'translation', 'locale' => 'ru', 'evidence_snapshot' => $fixture['evidence']]);
        $run->is_automated = true;
        $run->input_payload = $fixture['input'];
        $run->schema_version = 'translation-v2';
        $document = $fixture['output'];
        $document['version'] = 'translation-v2';
        $document['entity_mentions'] = [];
        foreach (['Federal Reserve' => 'Федеральная резервная система', 'Wednesday' => 'среду',
            'Federal Open Market Committee' => 'Федерального комитета по операциям на открытом рынке',
            'October' => 'октября', 'Committee' => 'комитету'] as $canonical => $localized) {
            $document['entity_mentions'][] = ['canonical' => $canonical, 'localized' => $localized];
        }

        return [$run, $document, $fixture];
    }

    public function test_real_russian_output_requires_explicit_localized_mentions_and_independent_verification(): void
    {
        [$run, $document, $fixture] = $this->translatedFixture();
        $this->assertContains('translation_entity_missing:Wednesday', $fixture['report']['critical']);
        $document = app(AiOutputSchema::class)->validate($document, $run);
        $this->assertSame([], app(AiQuality::class)->document($document, $run)['critical']);
        $assertions = app(AiSemanticVerification::class)->assertions($document);
        $this->assertCount(5, array_filter($assertions, fn (array $assertion): bool => str_starts_with($assertion['field'], 'entity_mentions.')));
        $this->assertSame('Federal Reserve => Федеральная резервная система', collect($assertions)->firstWhere('field', 'entity_mentions.0')['text']);
        $this->assertFalse(in_array('Wednesday', array_column($document['entity_mentions'], 'localized'), true));
        Http::assertNothingSent();
    }

    #[DataProvider('badMentionMappings')]
    public function test_missing_mentions_unmatched_mentions_and_unknown_canonical_names_fail(string $kind): void
    {
        [$run, $document] = $this->translatedFixture();
        if ($kind === 'missing') {
            array_pop($document['entity_mentions']);
        } elseif ($kind === 'absent_from_prose') {
            $document['entity_mentions'][0]['localized'] = 'Несуществующая организация';
        } elseif ($kind === 'unknown_canonical') {
            $document['entity_mentions'][0]['canonical'] = 'Unverified Organization';
            $this->expectException(ValidationException::class);
        } else {
            $document['entity_mentions'][] = $document['entity_mentions'][0];
            $this->expectException(ValidationException::class);
        }
        $document = app(AiOutputSchema::class)->validate($document, $run);
        $this->assertNotEmpty(app(AiQuality::class)->document($document, $run)['critical']);
    }

    public static function badMentionMappings(): array
    {
        return [['missing'], ['absent_from_prose'], ['unknown_canonical'], ['duplicate']];
    }

    public function test_a_wrong_entity_translation_cannot_pass_semantic_verification_with_true_overall_flags(): void
    {
        [$translation, $document] = $this->translatedFixture();
        $verification = new AiGenerationRun(['task' => 'verification', 'locale' => 'ru', 'evidence_snapshot' => $translation->evidence_snapshot]);
        $verification->schema_version = 'verification-v2';
        $assertions = app(AiSemanticVerification::class)->assertions($document);
        $verification->input_payload = ['assertions' => $assertions];
        $claims = collect($translation->evidence_snapshot['claims'])->keyBy('id');
        $assessments = [];
        foreach ($assertions as $assertion) {
            $id = $assertion['claim_ids'][0];
            $assessments[] = ['field' => $assertion['field'], 'verdict' => $assertion['field'] === 'entity_mentions.0' ? 'contradicted' : 'supported',
                'evidence' => [['claim_id' => $id, 'quote' => $claims[$id]['text']]], 'reason' => 'The incorrect localized organization must not pass.'];
        }
        $result = ['version' => 'verification-v2', 'supported' => true, 'faithful_translation' => true, 'original' => true,
            'correct_language' => true, 'assessments' => $assessments, 'issues' => []];
        $report = app(AiSemanticVerification::class)->report($result, $verification);
        $this->assertContains('semantic_contradicted', $report['critical']);
        Http::assertNothingSent();
    }

    public function test_japanese_chinese_month_notation_preserves_only_months_explicitly_present_in_source_dates(): void
    {
        [$run] = $this->translatedFixture();
        $quality = app(AiQuality::class);
        $this->assertSame([], $quality->text('2026年10月27–28日', $run->evidence_snapshot)['critical']);
        $this->assertContains('unsupported_number:11', $quality->text('2026年11月27–28日', $run->evidence_snapshot)['critical']);
        $this->assertContains('unsupported_number:10', $quality->text('The price was 10.', $run->evidence_snapshot)['critical']);
        $this->assertContains('unsupported_number:29', $quality->text('2026年10月27–29日', $run->evidence_snapshot)['critical']);
        $this->assertContains('unsupported_number:2027', $quality->text('2027年10月27–28日', $run->evidence_snapshot)['critical']);
        $this->assertSame('10月27日', $quality->normalizeCalendarMonths('10月27日', ['claims' => [['text' => 'October Company filed a document.']]]));
    }
}
