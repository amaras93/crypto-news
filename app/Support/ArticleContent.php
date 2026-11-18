<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ArticleContent
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->allowMediaHosts([(string) parse_url(config('app.url'), PHP_URL_HOST)])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(-1);

        foreach (['p', 'div', 'span', 'br', 'hr', 'strong', 'em', 'b', 'i', 'u', 's', 'sup', 'sub', 'blockquote', 'cite', 'q', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'pre', 'code', 'figure', 'figcaption', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr'] as $element) {
            $config = $config->allowElement($element);
        }

        foreach (range(1, 6) as $level) {
            $config = $config->allowElement('h'.$level, ['id']);
        }

        $this->sanitizer = new HtmlSanitizer($config
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope']));
    }

    public function sanitize(string $source): string
    {
        if (! preg_match('/<(?:p|div|span|h[1-6]|blockquote|cite|q|ul|ol|li|dl|dt|dd|a|strong|em|b|i|u|s|table|thead|tbody|tr|td|th|img|figure|pre|code|br|hr)\b/i', $source)) {
            $source = implode('', array_map(
                fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>',
                preg_split('/(?:\r?\n){2,}/u', trim($source)) ?: [],
            ));
        }

        return $this->sanitizer->sanitize($source);
    }

    /** @return array{html: HtmlString, text: string, toc: list<array{id: string, title: string, level: int}>, readingMinutes: ?int} */
    public function render(string $source, bool $sponsored = false): array
    {
        $safeHtml = $this->sanitize($source);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>'.$safeHtml.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $xpath = new DOMXPath($document);
        $toc = [];
        $anchors = [];
        $previousLevel = 1;

        foreach (iterator_to_array($xpath->query('//body//h1|//body//h2|//body//h3|//body//h4|//body//h5|//body//h6')) as $heading) {
            $title = Str::squish($heading->textContent);

            if ($title === '') {
                $heading->parentNode->removeChild($heading);

                continue;
            }

            $level = min(max(2, (int) substr($heading->nodeName, 1)), $previousLevel + 1);
            $id = 'article-section-'.(count($toc) + 1);
            $originalId = $heading->getAttribute('id');
            $replacement = $document->createElement('h'.$level);
            $replacement->setAttribute('id', $id);

            while ($heading->firstChild) {
                $replacement->appendChild($heading->firstChild);
            }

            $heading->parentNode->replaceChild($replacement, $heading);

            if ($originalId !== '') {
                $anchors[$originalId] ??= $id;
            }

            $toc[] = ['id' => $id, 'title' => $title, 'level' => $level];
            $previousLevel = $level;
        }

        foreach ($document->getElementsByTagName('a') as $link) {
            if ($sponsored && preg_match('#\A(?:https?:)?//#i', $link->getAttribute('href'))) {
                $link->setAttribute('rel', 'sponsored nofollow noopener noreferrer');
                $link->setAttribute('referrerpolicy', 'no-referrer');
            }
            $href = $link->getAttribute('href');
            $fragment = str_starts_with($href, '#') ? rawurldecode(substr($href, 1)) : null;

            if ($fragment !== null && isset($anchors[$fragment])) {
                $link->setAttribute('href', '#'.$anchors[$fragment]);
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
            if (! $image->hasAttribute('src') || $image->getAttribute('src') === '') {
                $image->parentNode->removeChild($image);

                continue;
            }

            $image->setAttribute('loading', 'lazy');
            $image->setAttribute('decoding', 'async');

            if (! $image->hasAttribute('alt')) {
                $image->setAttribute('alt', __('article.inline_image'));
            }

            foreach (['width', 'height'] as $dimension) {
                if ($image->hasAttribute($dimension) && (! ctype_digit($image->getAttribute($dimension)) || (int) $image->getAttribute($dimension) > 8192)) {
                    $image->removeAttribute($dimension);
                }
            }
        }

        foreach (iterator_to_array($document->getElementsByTagName('table')) as $table) {
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'article-table');
            $wrapper->setAttribute('role', 'region');
            $wrapper->setAttribute('tabindex', '0');
            $wrapper->setAttribute('aria-label', __('article.table'));
            $table->parentNode->insertBefore($wrapper, $table);
            $wrapper->appendChild($table);
        }

        $html = '';
        foreach ($body?->childNodes ?? [] as $node) {
            $html .= $document->saveHTML($node);
        }

        $withSpaces = preg_replace('#</(?:p|div|h[1-6]|li|blockquote|tr|td|th|figcaption)>|<br\s*/?>#i', ' ', $html);
        $text = Str::squish(html_entity_decode(strip_tags($withSpaces), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cjkCharacters = preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $text);
        $wordText = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', ' ', $text);
        $words = preg_match_all("/[\p{L}\p{N}]+(?:[’'-][\p{L}\p{N}]+)*/u", $wordText);

        return [
            'html' => new HtmlString($html),
            'text' => $text,
            'toc' => $toc,
            'readingMinutes' => $words > 0 || $cjkCharacters > 0 ? max(1, (int) ceil($words / 200 + $cjkCharacters / 400)) : null,
        ];
    }
}
