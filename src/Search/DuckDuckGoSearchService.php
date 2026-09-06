<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * DuckDuckGoSearchService
 *
 * A free, no-API-key fallback provider that scrapes DuckDuckGo's
 * lightweight HTML results page (html.duckduckgo.com/html/). Useful as the
 * last link in the SEARCH_PROVIDERS fallback chain when no paid API key is
 * configured or all paid providers failed for this request.
 *
 * Being an HTML scrape, this is inherently more fragile than a real API
 * (DuckDuckGo can change markup at any time) — it is deliberately kept as a
 * best-effort fallback, not the primary provider. It never throws; any
 * parsing failure just results in an empty result set, so the pipeline
 * degrades gracefully to evergreen/no-source content.
 */
final class DuckDuckGoSearchService implements SearchServiceInterface
{
    public function __construct(
        private readonly int $timeout,
        private readonly Logger $logger,
    ) {
    }

    public function search(string $query, int $maxResults = 5): array
    {
        $html = $this->fetchHtml($query);
        if ($html === null) {
            return [];
        }

        return $this->parseResults($html, $maxResults);
    }

    private function fetchHtml(string $query): ?string
    {
        $url = 'https://html.duckduckgo.com/html/?' . http_build_query(['q' => $query]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ContentBot/1.0; +https://t.me/Hoseiin_dev)',
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            $this->logger->error('DuckDuckGo search cURL error', ['errno' => $errNo]);
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('DuckDuckGo search returned non-2xx', ['http_code' => $httpCode]);
            return null;
        }

        return (string) $raw;
    }

    /**
     * Parses DuckDuckGo's HTML results page. Split out as its own method
     * (no network I/O) so it can be unit-tested with a saved HTML fixture.
     *
     * @return SearchResult[]
     */
    public function parseResults(string $html, int $maxResults): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (!class_exists(\DOMDocument::class)) {
            // The "dom"/"xml" PHP extension isn't installed on this host.
            // Fail closed (no results) instead of a fatal error - the chain
            // will simply move on / fall back to no-source content.
            $this->logger->warning('DuckDuckGoSearchService: DOMDocument class not available (php-xml/php-dom extension missing), skipping.');
            return [];
        }

        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')]");

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (count($results) >= $maxResults) {
                break;
            }

            /** @var \DOMElement $node */
            $href = $node->getAttribute('href');
            $title = trim($node->textContent);

            if ($href === '' || $title === '') {
                continue;
            }

            $realUrl = $this->extractRealUrl($href);
            if ($realUrl === null) {
                continue;
            }

            $results[] = new SearchResult(
                title: $title,
                url: $realUrl,
                snippet: '',
                publishedAt: null,
                source: parse_url($realUrl, PHP_URL_HOST) ?: null,
            );
        }

        return $results;
    }

    /**
     * DuckDuckGo's HTML results wrap the real URL inside a redirect link
     * like "//duckduckgo.com/l/?uddg=<url-encoded-real-url>&...". This
     * extracts the real target URL when present, or returns the href as-is
     * if it already looks like a direct URL.
     */
    private function extractRealUrl(string $href): ?string
    {
        if (str_starts_with($href, '//duckduckgo.com/l/') || str_starts_with($href, 'https://duckduckgo.com/l/') || str_starts_with($href, '/l/')) {
            $query = parse_url($href, PHP_URL_QUERY);
            if ($query === null) {
                return null;
            }
            parse_str($query, $params);
            $target = $params['uddg'] ?? null;
            return is_string($target) && $target !== '' ? urldecode($target) : null;
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return null;
    }
}
