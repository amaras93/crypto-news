@props(['article', 'readingMinutes' => null, 'newspaper' => false])
@if($newspaper)
<div class="paper-story-meta">
    <x-ui-icon name="calendar"/>
    <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->locale(app()->getLocale())->isoFormat('ll') }}</time>
    @if($readingMinutes)<span aria-hidden="true">·</span><span title="{{ __('article.reading_estimate') }}">{{ trans_choice('article.reading_time', $readingMinutes, ['count' => $readingMinutes]) }}</span>@endif
</div>
@else
<div class="card-meta">
    <x-ui-icon name="clock"/>
    <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->locale(app()->getLocale())->isoFormat('ll') }}</time>
    <span aria-hidden="true">·</span>
    <span>{{ $article->isDemo() ? __('ui.about') : $article->author?->name }}</span>
</div>
@endif
