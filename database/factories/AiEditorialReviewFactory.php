<?php

namespace Database\Factories;

use App\Models\AiEditorialReview;
use App\Models\AiGenerationRun;
use App\Models\ArticleTranslation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiEditorialReview> */
class AiEditorialReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['article_translation_id' => ArticleTranslation::factory(), 'ai_generation_run_id' => AiGenerationRun::factory(),
            'user_id' => User::factory()->editor(), 'decision' => 'rejected',
            'content_digest' => hash('sha256', 'fixture-content'), 'evidence_digest' => hash('sha256', 'fixture-evidence'),
            'notes' => 'Fictional audit fixture, not an approval or permission to publish.',
            'quality_report' => ['critical' => ['test_fixture_requires_review'], 'human_review_required' => true]];
    }
}
