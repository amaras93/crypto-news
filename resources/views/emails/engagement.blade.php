<!doctype html>
<html lang="{{ config('news.language_tags.'.app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>CryptoNews</title></head>
<body style="margin:0;padding:20px 10px;background:#f8f6f1;color:#171717;font-family:Georgia,'Times New Roman',serif;">
<table role="presentation" style="width:100%;max-width:640px;margin:0 auto;border-collapse:collapse;"><tr><td style="padding:22px 18px;border-top:3px double #565650;border-bottom:1px solid #a9a198;text-align:center;">
    <a href="{{ route('home', ['locale' => app()->getLocale()]) }}" style="color:#171717;text-decoration:none;font-size:42px;font-weight:bold;">CryptoNews</a>
</td></tr><tr><td style="padding:24px 18px;">
    @if($messageData['is_preview'] ?? false)<p style="padding:10px;border:1px solid #a9a198;color:#97171b;">{{ __('engagement.preview_notice') }}</p>@endif
    @if($messageData['kind'] === 'confirmation')
        <h1 style="font-size:27px;">{{ __('engagement.confirm_subscription') }}</h1><p style="line-height:1.7;">{{ __('engagement.confirm_mail') }}</p>
        <p><a href="{{ $messageData['confirmation_url'] }}" style="color:#97171b;font-weight:bold;">{{ __('engagement.confirm_action') }}</a></p>
        <p style="font-size:13px;line-height:1.6;">{{ __('engagement.ignore_mail') }}</p>
    @elseif($messageData['kind'] === 'price_alert')
        @php($event = $messageData['event'])
        <h1 style="font-size:27px;">{{ __('engagement.alert_subject') }}</h1>
        <p style="line-height:1.7;">{{ __('engagement.alert_message', ['asset' => __('market.assets.'.$event->asset), 'direction' => __('engagement.'.$event->direction), 'target' => rtrim(rtrim($event->target_price, '0'), '.'), 'unit' => __($event->unit === 'troy_ounce' ? 'market.usd_troy_ounce' : ($event->unit === 'pound' ? 'market.usd_pound' : 'engagement.usd_coin')), 'price' => rtrim(rtrim($event->observed_price, '0'), '.')]) }}</p>
        <p>{{ __('engagement.observed_at') }}: {{ gmdate('Y-m-d H:i', $event->observed_timestamp) }} UTC</p>
        <p><a href="{{ $messageData['account_url'] }}" style="color:#97171b;">{{ __('engagement.alerts') }}</a></p><p style="font-size:13px;">{{ __('engagement.price_alert_note') }}</p>
    @else
        <h1 style="font-size:27px;">{{ __('engagement.digest_subject_'.$messageData['frequency']) }}</h1>
        <p style="line-height:1.7;">{{ __('engagement.digest_intro') }}</p>
        @forelse($messageData['articles'] as $story)
            @php($storyTranslation = $story->translation(app()->getLocale()))
            <section style="padding:18px 0;border-top:1px solid #c9c4ba;">
                <h2 style="font-size:23px;line-height:1.2;margin:0 0 10px;"><a style="color:#171717;text-decoration:none;" href="{{ app(\App\Support\PublicUrl::class)->article($storyTranslation) }}">{{ $storyTranslation->title }}</a></h2>
                <p style="line-height:1.6;">{{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish(strip_tags($storyTranslation->excerpt ?: $storyTranslation->body ?? '')), 240) }}</p>
                <p style="font-size:12px;color:#635c56;"><time datetime="{{ $story->published_at->toIso8601String() }}">{{ $story->published_at->locale(app()->getLocale())->isoFormat('ll') }}</time> · <a href="{{ app(\App\Support\PublicUrl::class)->article($storyTranslation) }}" style="color:#97171b;">{{ __('engagement.read_article') }}</a></p>
            </section>
        @empty<p>{{ __('engagement.digest_empty') }}</p>@endforelse
    @endif
</td></tr><tr><td style="padding:18px;border-top:1px solid #a9a198;font-size:12px;line-height:1.7;">
    @if($messageData['unsubscribe_url'] ?? null)<p>{{ __('engagement.unsubscribe_mail') }}</p><a href="{{ $messageData['unsubscribe_url'] }}" style="color:#97171b;">{{ __('engagement.unsubscribe') }}</a>@endif
    <p>CryptoNews · {{ __('ui.footer_disclaimer') }}</p>
</td></tr></table>
</body></html>

