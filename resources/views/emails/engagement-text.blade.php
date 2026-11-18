CryptoNews

@if($messageData['is_preview'] ?? false){{ __('engagement.preview_notice') }}@endif

@if($messageData['kind'] === 'confirmation')
{{ __('engagement.confirm_mail') }}
{{ __('engagement.confirm_action') }}: {{ $messageData['confirmation_url'] }}
{{ __('engagement.ignore_mail') }}
@elseif($messageData['kind'] === 'price_alert')
@php($event = $messageData['event'])
{{ __('engagement.alert_message', ['asset' => __('market.assets.'.$event->asset), 'direction' => __('engagement.'.$event->direction), 'target' => $event->target_price, 'unit' => __($event->unit === 'troy_ounce' ? 'market.usd_troy_ounce' : ($event->unit === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')), 'price' => $event->observed_price]) }}
{{ __('engagement.observed_at') }}: {{ gmdate('Y-m-d H:i', $event->observed_timestamp) }} UTC
{{ $messageData['account_url'] }}
@else
{{ __('engagement.digest_subject_'.$messageData['frequency']) }}
{{ __('engagement.digest_intro') }}

@forelse($messageData['articles'] as $story)
@php($storyTranslation = $story->translation(app()->getLocale()))
{{ $storyTranslation->title }}
{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish(strip_tags($storyTranslation->excerpt ?: $storyTranslation->body ?? '')), 240) }}
{{ $story->published_at->locale(app()->getLocale())->isoFormat('ll') }}
{{ app(\App\Support\PublicUrl::class)->article($storyTranslation) }}

@empty
{{ __('engagement.digest_empty') }}
@endforelse
@endif

@if($messageData['unsubscribe_url'] ?? null){{ __('engagement.unsubscribe') }}: {{ $messageData['unsubscribe_url'] }}@endif
{{ __('engagement.price_alert_note') }}

