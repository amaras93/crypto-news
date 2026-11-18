<section class="newsletter" aria-labelledby="newsletter-heading">
    <div>
        <p class="eyebrow">{{ __('ui.newsletter_eyebrow') }}</p>
        <h2 id="newsletter-heading">@foreach(explode('\\n', __('ui.newsletter_title')) as $line){{ $line }}@unless($loop->last)<br>@endunless @endforeach</h2>
        <p>{{ __('engagement.digest_intro') }}</p>
        <a class="newsletter-status" href="{{ route('newsletter.form', ['locale' => app()->getLocale()]) }}"><x-ui-icon name="mail"/>{{ __('engagement.subscribe') }}</a>
        <p class="newsletter-disclaimer">{{ __('engagement.confirmation_help') }}</p>
    </div>
    <div class="newsletter-preview" aria-hidden="true">
        <x-ui-icon name="mail"/>
        <h3>{{ __('ui.newsletter_preview') }}</h3>
        <p>{{ __('ui.newsletter_preview_note') }}</p>
        <div class="preview-line"></div><div class="preview-line"></div><div class="preview-line short"></div>
    </div>
</section>
