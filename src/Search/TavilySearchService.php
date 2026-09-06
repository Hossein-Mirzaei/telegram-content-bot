<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * TavilySearchService
 *
 * Example real-world implementation of SearchServiceInterface, using the
 * Tavily Search API (https://tavily.com). Enable it by setting:
 *
 *   SEARCH_PROVIDER=tavily
 *   SEARCH_API_KEY=tvly-xxxxxxxx
 *
 * This class is a template: the same pattern (build request -> call API ->
 * map response into SearchResult[] -> never throw) can be copied to add
 * Serper, Bing, Brave, Google News (via SerpAPI or similar), etc. Only
 * SearchServiceFactory needs to know about the new class.
 */
final class TavilySearchService implements SearchServiceInterface
{
    private string $apiKey;
    private int $timeout;
    private Logger $logger;

    public function __construct(string $apiKey, int $timeout, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
        $this->logger = $logger;
    }

    public function search(string $query, int $maxResults = 5): array
    {
        if ($this->apiKey === '') {
            $this->logger->warning('Tavily search requested but SEARCH_API_KEY is empty');
            return [];
        }

        $ch = curl_init('https://api.tavily.com/search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'api_key' => $this->apiKey,
                'query' => $query,
                'search_depth' => 'basic',
                'max_results' => $maxResults,
                'include_answer' => false,
            ], JSON_UNESCAPED_UNICODE),
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
            $this->logger->error('Tavily search cURL error', ['errno' => $errNo]);
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('Tavily search returned non-2xx', ['http_code' => $httpCode]);
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['results']) || !is_array($decoded['results'])) {
            return [];
        }

        $results = [];
        foreach ($decoded['results'] as $item) {
            if (empty($item['url']) || empty($item['title'])) {
                continue;
            }
            $results[] = new SearchResult(
                title: (string) $item['title'],
                url: (string) $item['url'],
                snippet: (string) ($item['content'] ?? ''),
                publishedAt: $item['published_date'] ?? null,
                source: parse_url((string) $item['url'], PHP_URL_HOST) ?: null,
            );
        }

        return $results;
    }
}
