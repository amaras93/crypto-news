<?php

return [
    'enabled' => (bool) env('ADVERTISING_ENABLED', false),
    'direct_enabled' => (bool) env('DIRECT_ADVERTISING_ENABLED', false),
    'mode' => env('ADVERTISING_MODE', 'disabled'),
    'adsense_enabled' => (bool) env('ADSENSE_ENABLED', false),
    'publisher_id' => env('ADSENSE_PUBLISHER_ID', ''),
    'max_slots_per_page' => 2,
    'cache_seconds' => 30,
    'placements' => [
        'home_after_hero' => ['name' => 'Home — after editorial hero', 'route' => 'home', 'width' => 728, 'height' => 90, 'desktop' => false],
        'home_between_news' => ['name' => 'Home — after latest news', 'route' => 'home', 'width' => 728, 'height' => 90, 'desktop' => false],
        'home_sidebar' => ['name' => 'Home — desktop sidebar', 'route' => 'home', 'width' => 300, 'height' => 250, 'desktop' => true],
        'news_inline' => ['name' => 'News — between editorial rows', 'route' => 'news.index', 'width' => 728, 'height' => 90, 'desktop' => false],
        'news_sidebar' => ['name' => 'News — desktop support column', 'route' => 'news.index', 'width' => 300, 'height' => 250, 'desktop' => true],
        'article_before_body' => ['name' => 'Article — before body', 'route' => 'news.show', 'width' => 728, 'height' => 90, 'desktop' => false],
        'article_inline' => ['name' => 'Article — between substantial sections', 'route' => 'news.show', 'width' => 728, 'height' => 90, 'desktop' => false],
        'article_after_body' => ['name' => 'Article — after content', 'route' => 'news.show', 'width' => 728, 'height' => 90, 'desktop' => false],
        'article_sidebar' => ['name' => 'Article — desktop sidebar', 'route' => 'news.show', 'width' => 300, 'height' => 250, 'desktop' => true],
    ],
    'risk_categories' => [
        'general' => 'General non-financial advertising',
        'crypto_investment' => 'Cryptocurrency investment promotion — sensitive',
        'trading_platform' => 'Trading platform — sensitive',
        'financial_product' => 'Financial product — sensitive',
        'token_sale' => 'Token sale — sensitive',
        'yield_claim' => 'Yield or return claim — sensitive',
        'unverified_offer' => 'Unverified investment offer — blocked',
    ],
];
