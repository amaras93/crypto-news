<?php

namespace App\Support;

use App\Models\AdCampaign;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class AdvertisingService
{
    public function __construct(private AdvertisingSettings $settings, private AdPlacementResolver $resolver,
        private AdvertisingStatisticsService $statistics, private AdvertisingConsent $consent) {}

    public function publicContext(string $key): bool
    {
        $definition = config('advertising.placements.'.$key);

        return is_array($definition) && request()->routeIs($definition['route'])
            && ! request()->attributes->get('private_advertising_preview', false)
            && array_key_exists(app()->getLocale(), config('news.locales'));
    }

    /** @return array{provider: string, key: string, definition: array, banner: ?array, publisher: ?string, slot: ?string}|null */
    public function slot(string $key): ?array
    {
        if (! $this->publicContext($key) || ! $this->settings->enabled()) {
            return null;
        }
        $count = (int) request()->attributes->get('advertising_slot_count', 0);
        if ($count >= min(2, config('advertising.max_slots_per_page'))) {
            return null;
        }
        $placement = $this->resolver->placements()->get($key);
        if (! $placement) {
            return null;
        }
        $data = null;
        if ($placement->provider === 'direct' && $this->settings->directEnabled()) {
            $campaign = $this->resolver->resolve($placement, app()->getLocale());
            $this->statistics->record($placement, app()->getLocale(), $campaign?->id);
            if ($campaign) {
                $data = ['provider' => 'direct', 'banner' => $this->banner($campaign, app()->getLocale()), 'publisher' => null, 'slot' => null];
            }
        } elseif ($placement->provider === 'adsense' && ! config('advertising.placements.'.$key.'.desktop') && $this->settings->adsenseReady()
            && preg_match('/\A[0-9]{6,16}\z/D', (string) $placement->adsense_slot_id) && filled($this->consent->providerName()) && $this->consent->allowsExternalAds(request())) {
            request()->attributes->set('adsense_rendered', true);
            $data = ['provider' => 'adsense', 'banner' => null, 'publisher' => config('advertising.publisher_id'), 'slot' => $placement->adsense_slot_id];
        }
        if (! $data) {
            return null;
        }
        request()->attributes->set('advertising_slot_count', $count + 1);
        request()->attributes->set('advertising_rendered', true);

        return $data + ['key' => $key, 'definition' => config('advertising.placements.'.$key)];
    }

    /** @return array{id: int, image: string, url: string, alt: string, width: int, height: int} */
    public function banner(AdCampaign $campaign, string $locale): array
    {
        return ['id' => $campaign->id, 'image' => Storage::disk('public')->url($campaign->banner_image), 'url' => $campaign->destination_url,
            'alt' => $campaign->alt_text[$locale] ?? '', 'width' => $campaign->creative_width, 'height' => $campaign->creative_height];
    }

    /** @return array{before: HtmlString, after: HtmlString, can_split: bool} */
    public function articleSegments(HtmlString $html): array
    {
        $original = ['before' => $html, 'after' => new HtmlString(''), 'can_split' => false];
        if (! $this->settings->enabled()) {
            return $original;
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $errors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errors);
        }
        $body = $document->getElementsByTagName('body')->item(0);
        $nodes = iterator_to_array($body?->childNodes ?? []);
        $total = mb_strlen(trim($body?->textContent ?? ''));
        if ($total < 1800 || count($nodes) < 6) {
            return $original;
        }
        $length = 0;
        $split = null;
        foreach ($nodes as $index => $node) {
            if ($node->nodeName === 'h2' && $length >= 800 && $total - $length >= 800 && $index >= 3) {
                $split = $index;
                break;
            }
            $length += mb_strlen($node->textContent);
        }
        if ($split === null) {
            return $original;
        }
        $before = '';
        $after = '';
        foreach ($nodes as $index => $node) {
            if ($index < $split) {
                $before .= $document->saveHTML($node);
            } else {
                $after .= $document->saveHTML($node);
            }
        }

        return ['before' => new HtmlString($before), 'after' => new HtmlString($after), 'can_split' => true];
    }
}
