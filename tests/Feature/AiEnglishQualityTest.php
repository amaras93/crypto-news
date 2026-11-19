<?php

namespace Tests\Feature;

use App\AiRunStatus;
use App\Models\AiGenerationRun;
use App\Support\AiDrafting;
use App\Support\AiOutputSchema;
use App\Support\AiPricing;
use App\Support\AiQuality;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AiTestCase;

class AiEnglishQualityTest extends AiTestCase
{
    private function rejectedFixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/rejected-federal-reserve-draft.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function financialRun(): AiGenerationRun
    {
        $run = new AiGenerationRun(['evidence_snapshot' => $this->rejectedFixture()['evidence'], 'task' => 'english', 'locale' => 'en']);
        $run->is_automated = true;

        return $run;
    }

    private function originalDocument(): array
    {
        $document = $this->rejectedFixture()['document'];
        $document['headline'] = 'Federal Reserve publishes October meeting minutes';
        $document['excerpt'] = 'According to the Federal Reserve, records from its October 27–28, 2026 meeting are available. The release also explains the timetable used for minutes.';
        $document['seo_title'] = 'Federal Reserve releases records of October meeting';
        $document['seo_description'] = 'The Federal Reserve announced the release of October 27–28, 2026 meeting records and explained how the minutes reflect information available at the meeting.';
        $document['slug'] = 'federal-reserve-october-meeting-minutes';
        $document['takeaways'] = ['The Federal Reserve announced the release on Wednesday.',
            'The records cover the October 27–28, 2026 meeting.',
            'Minutes usually follow a policy decision by three weeks.'];
        $document['sections'][0]['heading'] = 'Release and publication timetable';
        $document['sections'][0]['paragraphs'][0]['text'] = 'According to the Federal Reserve, records of its Federal Open Market Committee meeting on October 27–28, 2026 are now available. The central bank announced the release on Wednesday.';
        $document['sections'][0]['paragraphs'][1]['text'] = 'The release explains the publication schedule: minutes usually follow a policy decision by three weeks. It also says the account of economic and financial conditions uses only what the Committee knew when it met.';
        $document['entities'] = ['Federal Reserve', 'Federal Open Market Committee', 'Committee'];
        $document['suggested_tags'] = ['Federal Reserve', 'meeting minutes'];
        $document['editorial_notes'] = [];

        return $document;
    }

    public function test_the_actual_rejected_output_still_fails_all_three_categories(): void
    {
        $fixture = $this->rejectedFixture();
        $document = app(AiOutputSchema::class)->validate($fixture['document'], $this->financialRun());
        $report = app(AiQuality::class)->document($document, $this->financialRun());
        foreach (['technical_evidence_id_in_public_text', 'unsupported_symbol:FOMC',
            'unsupported_entity:Board of Governors of the Federal Reserve System',
            'unsupported_entity:October 27–28, 2026', 'excessive_source_similarity'] as $failure) {
            $this->assertContains($failure, $report['critical']);
        }
        Http::assertNothingSent();
    }

    public function test_an_original_financial_brief_passes_with_internal_provenance_and_real_source_links(): void
    {
        $run = $this->financialRun();
        $document = app(AiOutputSchema::class)->validate($this->originalDocument(), $run);
        $report = app(AiQuality::class)->document($document, $run);
        $this->assertSame([], $report['critical']);
        $this->assertSame('quality-v3', $report['checks_version']);
        $this->assertFalse($report['human_review_required']);
        $body = app(AiOutputSchema::class)->body($document, $run->evidence_snapshot);
        foreach ($run->evidence_snapshot['claims'] as $index => $claim) {
            $this->assertSame([$claim['id']], $report['claim_links'][$index]['claim_ids']);
            $this->assertStringNotContainsString($claim['id'], $body);
            $this->assertStringContainsString('href="'.$claim['references'][0]['url'].'"', $body);
        }
        $this->assertStringContainsString('Board of Governors of the Federal Reserve System', $body);
        Http::assertNothingSent();
    }

    #[DataProvider('publicFields')]
    public function test_technical_ids_in_any_public_field_are_rejected(string $field): void
    {
        $document = $this->originalDocument();
        Arr::set($document, $field, Arr::get($document, $field).' '.$this->rejectedFixture()['evidence']['claims'][0]['id']);
        $report = app(AiQuality::class)->document($document, $this->financialRun());
        $this->assertContains('technical_evidence_id_in_public_text', $report['critical']);
    }

    public static function publicFields(): array
    {
        return array_map(fn (string $field): array => [$field], ['headline', 'excerpt', 'seo_title', 'seo_description',
            'slug', 'takeaways.0', 'sections.0.heading', 'sections.0.paragraphs.0.text', 'suggested_tags.0']);
    }

    #[DataProvider('individualFailures')]
    public function test_each_failure_is_rejected_without_the_other_two(string $kind, string $expected): void
    {
        $document = $this->originalDocument();
        if ($kind === 'entity') {
            $document['entities'][] = 'Invented Financial Corporation';
        } elseif ($kind === 'copy') {
            $document['sections'][0]['paragraphs'][0]['text'] = $this->rejectedFixture()['evidence']['claims'][0]['text'];
        } elseif ($kind === 'number') {
            $document['seo_description'] .= ' The rate reached 99.75%.';
        } else {
            $document['sections'][0]['paragraphs'][0]['text'] .= ' [99999999-cccc-4ccc-8ccc-999999999999]';
        }
        $this->assertContains($expected, app(AiQuality::class)->document($document, $this->financialRun())['critical']);
    }

    public static function individualFailures(): array
    {
        return [['entity', 'unsupported_entity:Invented Financial Corporation'], ['copy', 'excessive_source_similarity'],
            ['number', 'unsupported_number:99.75%'], ['foreign-id', 'technical_evidence_id_in_public_text']];
    }

    public function test_schema_constrains_entities_and_separates_citation_keys_from_public_prose(): void
    {
        $run = $this->financialRun();
        $schema = app(AiOutputSchema::class)->jsonSchema($run)['properties'];
        $this->assertSame(app(AiQuality::class)->allowedEntities($run->evidence_snapshot), $schema['entities']['items']['enum']);
        $this->assertNotContains('FOMC', $schema['entities']['items']['enum']);
        $this->assertNotContains('Board of Governors of the Federal Reserve System', $schema['entities']['items']['enum']);
        $paragraph = $schema['sections']['items']['properties']['paragraphs']['items']['properties'];
        $this->assertSame(array_column($run->evidence_snapshot['claims'], 'id'), $paragraph['claim_ids']['items']['enum']);
        $this->assertSame(1, $paragraph['claim_ids']['minItems']);
        $this->assertStringContainsString('Internal provenance only', $paragraph['claim_ids']['description']);
        $this->assertStringContainsString('no claim IDs', $paragraph['text']['description']);
        $this->assertSame(3, $schema['takeaways']['minItems']);
    }

    public function test_a_document_without_internal_citations_is_not_rescued_by_clean_prose(): void
    {
        $document = $this->originalDocument();
        $document['sections'][0]['paragraphs'][0]['claim_ids'] = [];
        $this->expectException(ValidationException::class);
        app(AiOutputSchema::class)->validate($document, $this->financialRun());
    }

    public function test_instructions_and_evidence_constraints_are_sent_without_any_extra_provider_call(): void
    {
        $this->live();
        $run = $this->pending(false);
        Http::fake(['api.openai.com/*' => Http::response($this->response($run))]);
        $this->complete($run);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $instructions = $data['input'][0]['content'];
            $input = json_decode($data['input'][1]['content'], true);
            $this->assertStringContainsString('Put supporting IDs ONLY in each paragraph claim_ids array', $instructions);
            $this->assertStringContainsString('never reproduce a sequence of 12 source words', $instructions);
            $this->assertStringContainsString('Do not invent aliases, abbreviations or acronyms', $instructions);
            $this->assertSame(['Sandbox Exchange', 'SBX'], $input['editorial_constraints']['allowed_entities']);
            $this->assertSame([], $input['editorial_constraints']['allowed_numerical_strings']);
            $this->assertSame(11, $input['editorial_constraints']['maximum_consecutive_source_words']);
            $this->assertSame(2, $input['editorial_constraints']['minimum_distinct_paragraphs']);

            return true;
        });
    }

    public function test_longer_instructions_are_included_in_spending_reservations(): void
    {
        $rates = ['input' => 750000, 'cached' => 75000, 'output' => 4500000, 'write' => 0];
        $pricing = app(AiPricing::class);
        $this->assertSame(750, $pricing->reservation([], [], $rates, str_repeat('x', 1000)) - $pricing->reservation([], [], $rates));
    }

    public function test_storage_rechecks_content_instead_of_trusting_an_old_passing_report(): void
    {
        $pipeline = app(AiDrafting::class);
        $run = $this->pending();
        $pipeline->process($run->id, false);
        $pipeline->validate($run->id, false);
        $this->assertSame(AiRunStatus::Validated, $run->fresh()->status);
        $document = $run->fresh()->output_document;
        $document['headline'] .= ' '.$run->evidence_snapshot['claims'][0]['id'];
        $run->forceFill(['output_document' => $document])->save();
        $pipeline->store($run->id);
        $this->assertSame(AiRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('critical_quality_failure', $run->fresh()->error_code);
        $this->assertDatabaseCount('articles', 0);
        Http::assertNothingSent();
    }
}
