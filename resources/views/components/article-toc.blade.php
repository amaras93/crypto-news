@props(['headings'])
@if($headings)
    <details {{ $attributes->class('article-toc') }} open>
        <summary><span class="toc-heading"><x-ui-icon name="book"/>{{ __('article.toc') }}</span><x-ui-icon name="chevron"/></summary>
        <ol>
            @foreach($headings as $heading)
                <li class="toc-level-{{ $heading['level'] }}"><a href="#{{ $heading['id'] }}"><span class="toc-number" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span>{{ $heading['title'] }}</span></a></li>
            @endforeach
        </ol>
    </details>
@endif
