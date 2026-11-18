@props(['title', 'href' => null, 'label' => null])
<div class="section-heading">
    <h2><span class="heading-mark" aria-hidden="true"></span>{{ $title }}</h2>
    @if($href) <a class="text-link" href="{{ $href }}">{{ $label ?? __('ui.all_news') }} <x-ui-icon/></a> @endif
    {{ $slot }}
</div>
