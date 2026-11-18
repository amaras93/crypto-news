<?php

namespace App\Support;

use App\Models\NewsSource;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Normalizer;
use Throwable;

class NewsNormalizer
{
    public function __construct(private FeedUrlSafety $urls) {}

    /** @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public function normalize(array $entry, NewsSource $source): array
    {
        $headline = $this->text((string) ($entry['title'] ?? ''), 500);
        if ($headline === '') {
            throw new FeedFailure('missing_headline');
        }
        $original = $this->link((string) ($entry['url'] ?? ''), $source);
        $canonical = null;
        if (! empty($entry['canonical'])) {
            $canonical = $this->link((string) $entry['canonical'], $source);
        }
        $normalizedUrl = $this->normalizedUrl($canonical ?? $original);
        $language = strtolower((string) ($entry['language'] ?? $source->language));
        if (! preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,3}\z/D', $language)) {
            $language = $source->language;
        }
        $summary = $source->summary_permitted ? $this->text((string) ($entry['summary'] ?? ''), 1000) : null;
        $published = null;
        $warnings = [];
        if (! empty($entry['published'])) {
            try {
                $rawDate = (string) $entry['published'];
                $iso = preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $rawDate);
                $rss = preg_match('/\A(?:([A-Za-z]{3}),\s*)?\d{1,2}\s+[A-Za-z]{3}\s+\d{2,4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:[+-]\d{4}|UT|GMT|[ECMP][SD]T)\z/D', $rawDate, $dateParts);
                if (! $iso && ! $rss) {
                    throw new FeedFailure('invalid_timestamp');
                }
                $date = new DateTimeImmutable(preg_replace('/\A[A-Za-z]{3},\s*/', '', $rawDate));
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
                    || (! empty($dateParts[1]) && strcasecmp($dateParts[1], $date->format('D')) !== 0)) {
                    throw new FeedFailure('invalid_timestamp');
                }
                $candidateDate = CarbonImmutable::instance($date);
                if ($candidateDate->year < 1970 || $candidateDate->greaterThan(now()->addMinutes(10))) {
                    throw new FeedFailure('invalid_timestamp');
                }
                $published = $candidateDate->utc();
            } catch (Throwable) {
                $warnings[] = 'invalid_timestamp';
            }
        } else {
            $warnings[] = 'missing_timestamp';
        }
        $normalizedHeadline = $this->headline($headline);
        $externalId = trim((string) ($entry['id'] ?? ''));
        if (preg_match('/[\x00-\x1f\x7f]/', $externalId)) {
            throw new FeedFailure('invalid_external_id');
        }

        return [
            'headline' => $headline, 'original_url' => $original, 'canonical_url' => $canonical,
            'url_hash' => hash('sha256', $normalizedUrl), 'normalized_headline' => $normalizedHeadline,
            'headline_hash' => hash('sha256', $language.'|'.$normalizedHeadline),
            'content_fingerprint' => hash('sha256', $language.'|'.$normalizedHeadline.'|'.$this->headline($summary ?? '')),
            'language' => $language, 'summary' => $summary ?: null, 'published_at' => $published,
            'external_id' => $externalId === '' ? null : mb_substr($externalId, 0, 512),
            'external_hash' => hash('sha256', $externalId === '' ? 'url:'.$normalizedUrl : 'id:'.$externalId),
            'warnings' => $warnings,
        ];
    }

    public function normalizedUrl(string $url): string
    {
        $safe = $this->urls->inspect(explode('#', $url, 2)[0]);
        $parts = parse_url($safe['url']);
        $pairs = [];
        $keys = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true)) {
                continue;
            }
            $pairs[] = $pair;
            $keys[] = $key;
        }
        if (count($keys) === count(array_unique($keys))) {
            sort($pairs, SORT_STRING);
        }

        return 'https://'.$safe['host'].($parts['path'] ?? '/').($pairs ? '?'.implode('&', $pairs) : '');
    }

    public function headline(string $headline): string
    {
        $headline = Normalizer::normalize($headline, Normalizer::FORM_KC) ?: $headline;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($headline)) ?? '');
    }

    private function link(string $url, NewsSource $source): string
    {
        $safe = $this->urls->inspect(explode('#', $url, 2)[0]);
        $allowed = [
            $this->urls->inspect($source->website)['host'],
            $this->urls->inspect($source->feed_url)['host'],
        ];
        $allowed = array_merge($allowed, array_map(fn (string $host): string => str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host, $allowed));
        if (! in_array($safe['host'], $allowed, true)) {
            throw new FeedFailure('foreign_article_host');
        }

        return $safe['url'];
    }

    private function text(string $text, int $max): string
    {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[\p{C}]+/u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, $max);
    }
}
