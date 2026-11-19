<?php

namespace App\Support;

use App\Models\AiGenerationRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiSemanticVerification
{
    /** @return list<array{field: string, text: string, claim_ids: list<string>}> */
    public function assertions(array $document): array
    {
        $allIds = collect($document['sections'])->flatMap(fn (array $section): array => collect($section['paragraphs'])->pluck('claim_ids')->flatten()->all())->unique()->values()->all();
        $fields = [];
        foreach (['headline', 'excerpt', 'seo_title', 'seo_description', 'slug'] as $field) {
            $fields[] = ['field' => $field, 'text' => $document[$field], 'claim_ids' => $allIds];
        }
        foreach ($document['takeaways'] as $index => $text) {
            $fields[] = ['field' => 'takeaways.'.$index, 'text' => $text, 'claim_ids' => $allIds];
        }
        foreach ($document['sections'] as $index => $section) {
            $fields[] = ['field' => 'sections.'.$index.'.heading', 'text' => $section['heading'], 'claim_ids' => $allIds];
            foreach ($section['paragraphs'] as $paragraphIndex => $paragraph) {
                $fields[] = ['field' => 'sections.'.$index.'.paragraphs.'.$paragraphIndex.'.text', 'text' => $paragraph['text'], 'claim_ids' => $paragraph['claim_ids']];
            }
        }

        foreach ($document['entity_mentions'] ?? [] as $index => $mention) {
            $fields[] = ['field' => 'entity_mentions.'.$index, 'text' => $mention['canonical'].' => '.$mention['localized'], 'claim_ids' => $allIds];
        }

        return $fields;
    }

    public function schema(AiGenerationRun $run): array
    {
        $object = fn (array $properties): array => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $reference = $object(['claim_id' => ['type' => 'string', 'enum' => array_column($run->evidence_snapshot['claims'], 'id')],
            'quote' => ['type' => 'string', 'minLength' => 12, 'maxLength' => 1500]]);
        $assessment = $object(['field' => ['type' => 'string'],
            'verdict' => ['type' => 'string', 'enum' => ['supported', 'contradicted', 'absent', 'uncertain']],
            'evidence' => ['type' => 'array', 'items' => $reference, 'maxItems' => 5],
            'reason' => ['type' => 'string', 'maxLength' => 500]]);

        return $object(['version' => ['type' => 'string', 'enum' => ['verification-v2']],
            'supported' => ['type' => 'boolean'], 'faithful_translation' => ['type' => 'boolean'],
            'original' => ['type' => 'boolean'], 'correct_language' => ['type' => 'boolean'],
            'assessments' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $assessment],
            'issues' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 500]]]);
    }

    public function validate(array $document, AiGenerationRun $run): array
    {
        return Validator::make($document, [
            'version' => ['required', Rule::in(['verification-v2'])],
            'supported' => ['required', 'boolean'], 'faithful_translation' => ['required', 'boolean'],
            'original' => ['required', 'boolean'], 'correct_language' => ['required', 'boolean'],
            'issues' => ['present', 'array', 'max:30'], 'issues.*' => ['string', 'max:500'],
            'assessments' => ['required', 'array', 'max:50'],
            'assessments.*' => ['array:field,verdict,evidence,reason'],
            'assessments.*.field' => ['required', 'string', 'distinct', 'max:100'],
            'assessments.*.verdict' => ['required', Rule::in(['supported', 'contradicted', 'absent', 'uncertain'])],
            'assessments.*.reason' => ['present', 'string', 'max:500'],
            'assessments.*.evidence' => ['present', 'array', 'max:5'],
            'assessments.*.evidence.*' => ['array:claim_id,quote'],
            'assessments.*.evidence.*.claim_id' => ['required', Rule::in(array_column($run->evidence_snapshot['claims'], 'id'))],
            'assessments.*.evidence.*.quote' => ['required', 'string', 'min:12', 'max:1500'],
        ])->validate();
    }

    public function failureContext(array $document, array $report): array
    {
        return ['checks' => Arr::only($document, ['version', 'supported', 'faithful_translation', 'original', 'correct_language', 'issues']),
            'critical' => $report['critical'] ?? [],
            'failed_fields' => collect($document['assessments'] ?? [])->where('verdict', '!=', 'supported')
                ->map(fn (array $assessment): array => Arr::only($assessment, ['field', 'verdict', 'reason']))->values()->all()];
    }

    public function inconsistent(array $document): bool
    {
        return ($document['issues'] ?? null) === []
            && (($document['supported'] ?? null) === false || ($document['faithful_translation'] ?? null) === false
                || ($document['original'] ?? null) === false || ($document['correct_language'] ?? null) === false);
    }

    public function report(array $document, AiGenerationRun $run): array
    {
        $critical = [];
        if ($document['supported'] !== true || $document['faithful_translation'] !== true || $document['original'] !== true
            || $document['correct_language'] !== true || $document['issues'] !== []) {
            $critical[] = 'semantic_verification_failed';
        }
        if ($run->schema_version === 'verification-v2') {
            if ($this->inconsistent($document)) {
                $critical[] = 'semantic_report_inconsistent';
            }
            $assertions = collect($run->input_payload['assertions'] ?? [])->keyBy('field');
            $assessments = collect($document['assessments'])->keyBy('field');
            if ($assertions->isEmpty() || $assertions->keys()->sort()->values()->all() !== $assessments->keys()->sort()->values()->all()) {
                $critical[] = 'semantic_field_coverage_incomplete';
            }
            $claims = collect($run->evidence_snapshot['claims'])->keyBy('id');
            foreach ($document['assessments'] as $assessment) {
                if ($assessment['verdict'] !== 'supported') {
                    $critical[] = 'semantic_'.$assessment['verdict'];
                }
                if ($assessment['verdict'] === 'supported' && $assessment['evidence'] === []) {
                    $critical[] = 'semantic_evidence_missing';
                }
                foreach ($assessment['evidence'] as $reference) {
                    $claim = $claims->get($reference['claim_id']);
                    if (! $claim || ! str_contains($claim['text'], $reference['quote'])
                        || ! in_array($reference['claim_id'], $assertions->get($assessment['field'])['claim_ids'] ?? [], true)) {
                        $critical[] = 'semantic_evidence_reference_invalid';
                    }
                }
            }
        }

        return ['critical' => array_values(array_unique($critical)), 'checks_version' => $run->schema_version,
            'human_review_required' => false, 'assessments' => $document['assessments'] ?? [], 'issues' => $document['issues']];
    }
}
