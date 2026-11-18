@props(['name' => 'arrow'])
<svg {{ $attributes->class('icon') }} viewBox="0 0 24 24" aria-hidden="true">
    @switch($name)
        @case('search') <circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/> @break
        @case('menu') <path d="M4 6h16M4 12h16M4 18h16"/> @break
        @case('chevron') <path d="m7 10 5 5 5-5"/> @break
        @case('clock') <circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/> @break
        @case('mail') <rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/> @break
        @case('back') <path d="M20 12H4m7-7-7 7 7 7"/> @break
        @case('globe') <circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/> @break
        @case('close') <path d="m6 6 12 12M6 18 18 6"/> @break
        @case('copy') <rect x="8" y="8" width="12" height="13" rx="2"/><path d="M15 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/> @break
        @case('up') <path d="M12 20V4m-7 7 7-7 7 7"/> @break
        @case('share') <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/> @break
        @case('calendar') <rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M7 3v4M17 3v4"/> @break
        @case('sparkles') <path d="m12 3 2.4 6.6L21 12l-6.6 2.4L12 21l-2.4-6.6L3 12l6.6-2.4L12 3ZM20 2v4M18 4h4"/> @break
        @case('book') <path d="M12 6v15M3 4c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2Z"/> @break
        @case('brand') <path d="m17 6-8 0-5 6 5 6h8M10 8l-3 4 3 4" stroke-width="2.5"/> @break
        @default <path d="M4 12h16m-7-7 7 7-7 7"/>
    @endswitch
</svg>
