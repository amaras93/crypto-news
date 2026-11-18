<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use App\Models\User;
use Illuminate\Support\Str;

class AiDemoFixtures
{
    /** @return array{user: User, candidate: NewsCandidate} */
    public function create(): array
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        $actor = new User(['name' => 'CryptoNews offline test desk', 'email' => 'offline-'.Str::uuid().'@example.invalid',
            'password' => Str::random(64)]);
        $actor->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
        $source = new NewsSource(['name' => 'Fictional training publisher', 'website' => 'https://example.org/',
            'feed_url' => 'https://example.org/offline-'.Str::uuid(), 'feed_hash' => hash('sha256', Str::uuid()),
            'source_type' => 'rss', 'language' => 'en', 'enabled' => true, 'priority' => 3,
            'summary_permitted' => false, 'licensing_notes' => 'Fictional first-party fixture authored for private offline testing.',
            'item_limit' => 10]);
        $source->forceFill(['approved_by' => $actor->id, 'approved_at' => now(), 'ai_usage_allowed' => true,
            'publisher_group' => 'offline-fixture', 'is_primary' => true, 'is_simulated' => true, 'version' => 1])->save();
        $candidate = new NewsCandidate(['headline' => 'Fictional documentation for an offline editorial demonstration',
            'original_url' => 'https://example.org/offline-'.Str::uuid(), 'url_hash' => hash('sha256', Str::uuid()),
            'headline_hash' => hash('sha256', Str::uuid()), 'content_fingerprint' => hash('sha256', Str::uuid()),
            'language' => 'en', 'published_at' => now()->subHour(), 'discovered_at' => now(), 'classification' => []]);
        $candidate->forceFill(['status' => CandidateStatus::ApprovedForDraft, 'is_simulated' => true,
            'reviewed_by' => $actor->id, 'reviewed_at' => now()])->save();
        $reference = CandidateSourceReference::create(['news_candidate_id' => $candidate->id, 'news_source_id' => $source->id,
            'original_url' => $candidate->original_url, 'headline' => $candidate->headline, 'language' => 'en',
            'external_id' => 'offline-'.Str::uuid(), 'external_hash' => hash('sha256', Str::uuid()),
            'source_published_at' => now()->subHour(), 'first_seen_at' => now(), 'last_seen_at' => now()]);
        foreach ([
            'Sandbox Exchange (SBX), a fictional training organization, prepared documentation so that editors can practice reviewing attributed factual claims in an offline environment.',
            'The documentation belongs to an invented educational exercise unrelated to real trading activity. Its source material is original test content intended only for private editorial demonstrations.',
        ] as $claim) {
            app(AiEvidence::class)->save($actor, $candidate, ['claim' => $claim, 'reference_ids' => [$reference->id],
                'entities' => ['Sandbox Exchange', 'SBX'], 'figures' => [], 'material' => true,
                'review_notes' => 'Verified against our original fictional training fixture; not a real-world news claim.', 'confirmed' => true]);
        }

        return ['user' => $actor, 'candidate' => $candidate];
    }
}
