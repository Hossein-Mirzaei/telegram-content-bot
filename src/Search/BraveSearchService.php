<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * BraveSearchService
 *
 * Provider for the Brave Search API (https://api.search.brave.com).
 * Enable with:
 *   SEARCH_PROVIDERS=brave (or include it in the fallback chain)
 *   SEARCH_BRAVE_KEY=your-key
 */
final class BraveSearchService implements SearchServiceInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout,
        private readonly Logger $logger,
    ) {
    }

    public function search(string $query, int $maxResults = 5): array
    {
        if ($this->apiKey === '') {
            $this->logger->warning('Brave search requested but SEARCH_BRAVE_KEY is empty');
            return [];
        }

        $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
            'q' => $query,
            'count' => $maxResults,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Subscription-Token: ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $errNo = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $raw === false) {
            $this->logger->error('Brave search cURL error', ['errno' => $errNo]);
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('Brave search returned non-2xx', ['http_code' => $httpCode]);
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        $items = $decoded['web']['results'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $results = [];
        foreach ($items as $item) {
            if (empty($item['url']) || empty($item['title'])) {
                continue;
            }
            $results[] = new SearchResult(
                title: (string) $item['title'],
                url: (string) $item['url'],
                snippet: (string) ($item['description'] ?? ''),
                publishedAt: $item['age'] ?? null,
                source: parse_url((string) $item['url'], PHP_URL_HOST) ?: null,
            );
        }

        return $results;
    }
}
