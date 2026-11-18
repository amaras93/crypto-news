@props(['filtered' => false])
<div class="empty-state">
    <x-ui-icon :name="$filtered ? 'search' : 'book'"/>
    <h2>{{ __($filtered ? 'ui.no_results' : 'ui.no_stories') }}</h2>
    <p>{{ __($filtered ? 'ui.no_results_note' : 'ui.no_stories_note') }}</p>
    @if($filtered)
        <a href="{{ route('news.index', ['locale' => app()->getLocale()]) }}" class="button secondary">{{ __('ui.clear_filters') }} <x-ui-icon/></a>
    @endif
</div>
