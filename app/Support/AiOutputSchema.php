<?php

namespace App\Support;

use App\Models\AiGenerationRun;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiOutputSchema
{
    public const SECTIONS = ['story', 'background', 'why_it_matters', 'implications'];

    public function jsonSchema(AiGenerationRun $run): array
    {
        $claims = array_column($run->evidence_snapshot['claims'], 'id');
        $string = ['type' => 'string'];
        $paragraph = $this->object(['text' => $string, 'claim_ids' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $claims]]]);
        $section = $this->object(['key' => ['type' => 'string', 'enum' => self::SECTIONS], 'heading' => $string,
            'paragraphs' => ['type' => 'array', 'items' => $paragraph]]);

        return $this->object([
            'version' => ['type' => 'string', 'enum' => ['draft-v1']],
            'language' => ['type' => 'string', 'enum' => [$run->locale]],
            'headline' => $string, 'excerpt' => $string, 'seo_title' => $string, 'seo_description' => $string,
            'slug' => $string, 'takeaways' => ['type' => 'array', 'items' => $string],
            'sections' => ['type' => 'array', 'items' => $section],
            'entities' => ['type' => 'array', 'items' => $string],
            'suggested_tags' => ['type' => 'array', 'items' => $string],
            'editorial_notes' => ['type' => 'array', 'items' => $string],
            'unsupported_claim_warnings' => ['type' => 'array', 'items' => $string],
        ]);
    }

    public function validate(array $document, AiGenerationRun $run): array
    {
        $keys = array_keys($this->jsonSchema($run)['properties']);
        $claims = array_column($run->evidence_snapshot['claims'], 'id');
        $plain = ['required', 'string', 'not_regex:/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u'];
        $values = Validator::make(['document' => $document], [
            'document' => ['required', 'array:'.implode(',', $keys)],
            'document.version' => ['required', Rule::in(['draft-v1'])], 'document.language' => ['required', Rule::in([$run->locale])],
            'document.headline' => [...$plain, 'max:200'], 'document.excerpt' => [...$plain, 'min:40', 'max:500'],
            'document.seo_title' => [...$plain, 'max:100'], 'document.seo_description' => [...$plain, 'max:250'],
            'document.slug' => ['required', 'string', 'max:160', 'lowercase', 'regex:/\A[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*\z/u'],
            'document.takeaways' => ['required', 'array', 'min:3', 'max:5'], 'document.takeaways.*' => [...$plain, 'max:300'],
            'document.sections' => ['required', 'array', 'min:1', 'max:4'],
            'document.sections.*' => ['required', 'array:key,heading,paragraphs'],
            'document.sections.*.key' => ['required', 'distinct', Rule::in($run->task === 'section' ? [$run->section_key] : self::SECTIONS)],
            'document.sections.*.heading' => [...$plain, 'max:100'],
            'document.sections.*.paragraphs' => ['required', 'array', 'min:1', 'max:6'],
            'document.sections.*.paragraphs.*' => ['required', 'array:text,claim_ids'],
            'document.sections.*.paragraphs.*.text' => [...$plain, 'min:50', 'max:1800'],
            'document.sections.*.paragraphs.*.claim_ids' => ['required', 'array', 'min:1', 'max:5'],
            'document.sections.*.paragraphs.*.claim_ids.*' => ['required', Rule::in($claims)],
            'document.entities' => ['present', 'array', 'max:30'], 'document.entities.*' => [...$plain, 'max:100'],
            'document.suggested_tags' => ['present', 'array', 'max:8'], 'document.suggested_tags.*' => [...$plain, 'max:50'],
            'document.editorial_notes' => ['present', 'array', 'max:10'], 'document.editorial_notes.*' => [...$plain, 'max:500'],
            'document.unsupported_claim_warnings' => ['present', 'array', 'max:10'], 'document.unsupported_claim_warnings.*' => [...$plain, 'max:500'],
        ])->validate();

        return $values['document'];
    }

    public function body(array $document, array $evidence): string
    {
        $claims = collect($evidence['claims'])->keyBy('id');
        $body = '';
        foreach ($document['sections'] as $section) {
            $body .= '<h2 id="ai-'.$section['key'].'">'.e($section['heading']).'</h2>';
            foreach ($section['paragraphs'] as $paragraph) {
                $body .= '<p>'.e($paragraph['text']);
                $urls = [];
                foreach ($paragraph['claim_ids'] as $claimId) {
                    foreach ($claims[$claimId]['references'] as $source) {
                        $urls[$source['url']] = $source['publisher'];
                    }
                }
                foreach ($urls as $url => $publisher) {
                    $body .= ' <a href="'.e($url).'">'.e($publisher).'</a>';
                }
                $body .= '</p>';
            }
        }

        return app(ArticleContent::class)->sanitize($body);
    }

    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
