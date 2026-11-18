<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use XMLReader;

class FeedParser
{
    /** @return list<array{id: ?string, title: string, url: string, canonical: ?string, summary: ?string, published: ?string, language: ?string}> */
    public function parse(string $xml, string $type, int $limit): array
    {
        if (strlen($xml) > config('discovery.max_feed_bytes')) {
            throw new FeedFailure('feed_too_large');
        }
        if (! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0")
            || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new FeedFailure('unsafe_xml');
        }
        $old = libxml_use_internal_errors(true);
        try {
            $reader = new XMLReader;
            if (! $reader->XML($xml, 'UTF-8', LIBXML_NONET | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0))) {
                throw new FeedFailure('invalid_xml');
            }
            try {
                $elements = 0;
                while ($reader->read()) {
                    if ($reader->depth > 32 || ($reader->nodeType === XMLReader::ELEMENT && ++$elements > 20000)) {
                        throw new FeedFailure('xml_structure_limit');
                    }
                }
            } finally {
                $reader->close();
            }
            if (libxml_get_errors()) {
                throw new FeedFailure('invalid_xml');
            }
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | (defined('LIBXML_NO_XXE') ? LIBXML_NO_XXE : 0))) {
                throw new FeedFailure('invalid_xml');
            }
            $xpath = new DOMXPath($document);
            if ($xpath->query('//*')->length > 20000 || $xpath->query('//*[count(ancestor::*) > 32]')->length > 0) {
                throw new FeedFailure('xml_structure_limit');
            }
            $root = $document->documentElement;
            $atom = $root?->localName === 'feed' && $root->namespaceURI === 'http://www.w3.org/2005/Atom';
            if (($type === 'atom' && ! $atom) || ($type === 'rss' && ! in_array($root?->localName, ['rss', 'RDF'], true))) {
                throw new FeedFailure('unexpected_feed_format');
            }
            $nodes = $xpath->query($atom ? '/*[local-name()="feed"]/*[local-name()="entry"]' : '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"] | /*[local-name()="RDF"]/*[local-name()="item"]');
            $items = [];
            foreach ($nodes as $node) {
                if (count($items) >= min(config('discovery.max_entries'), max(1, $limit))) {
                    break;
                }
                $url = $atom
                    ? $xpath->evaluate('string(./*[local-name()="link" and (not(@rel) or @rel="alternate")][1]/@href)', $node)
                    : $this->value($xpath, $node, 'link');
                $items[] = [
                    'id' => $this->value($xpath, $node, $atom ? 'id' : 'guid', 4096),
                    'title' => $this->value($xpath, $node, 'title', 4000) ?? '',
                    'url' => $url ?? '',
                    'canonical' => $xpath->evaluate('string(./*[local-name()="link" and @rel="canonical"][1]/@href)', $node) ?: null,
                    'summary' => $this->value($xpath, $node, $atom ? 'summary' : 'description', 8000),
                    'published' => $this->value($xpath, $node, $atom ? 'published' : 'pubDate', 128)
                        ?? $this->value($xpath, $node, $atom ? 'updated' : 'date', 128),
                    'language' => $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')
                        ?: ($root->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang')
                        ?: ($xpath->evaluate('string(/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="language"])') ?: null)),
                ];
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private function value(DOMXPath $xpath, DOMElement $node, string $name, int $max = 2048): ?string
    {
        $value = trim($xpath->evaluate('string(./*[local-name()="'.$name.'"][1])', $node));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
