@props(['translation'])
@php($points = $translation->summaryPoints())
@if($points)
    <section class="article-summary" aria-label="{{ __($translation->summary_source === 'ai' ? 'article.ai_summary' : 'article.takeaways') }}">
        <div class="summary-heading">
            <span class="summary-icon" aria-hidden="true"><x-ui-icon name="sparkles"/></span>
            <h2>{{ __($translation->summary_source === 'ai' ? 'article.ai_summary' : 'article.takeaways') }}</h2>
        </div>
        @if($translation->summary_source === 'ai')<p class="article-summary-note">{{ __('article.ai_note') }}</p>@endif
        <ul>@foreach($points as $point)<li>{{ $point }}</li>@endforeach</ul>
    </section>
@endif
