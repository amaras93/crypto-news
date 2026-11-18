<!doctype html>
<html lang="{{ config('news.language_tags.'.app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>CryptoNews — {{ __('advertising.advertising') }}</title>
<style>body{background:#f8f6f1;color:#171717;font:16px/1.6 Georgia,serif;margin:24px auto;padding:0 16px;max-width:1000px}aside{border-block:1px solid #bcb3a8;padding:14px 0}img{display:block;max-width:100%;height:auto;max-height:400px;object-fit:contain}strong{color:#97171b}a{color:inherit}</style></head>
<body><h1>CryptoNews</h1><p><strong>{{ __('advertising.preview') }}</strong></p><aside aria-label="{{ __('advertising.advertisement') }}"><p>{{ __('advertising.advertisement') }}</p><x-direct-banner :banner="$banner"/></aside></body>
</html>
