<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * SerperSearchService
 *
 * Provider for https://serper.dev (Google Search results API).
 * Enable with:
 *   SEARCH_PROVIDERS=serper (or include it in the fallback chain)
 *   SEARCH_SERPER_KEY=your-key
 */
final class SerperSearchService implements SearchServiceInterface
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
            $this->logger->warning('Serper search requested but SEARCH_SERPER_KEY is empty');
            return [];
        }

        $ch = curl_init('https://google.serper.dev/search');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'q' => $query,
                'num' => $maxResults,
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
            $this->logger->error('Serper search cURL error', ['errno' => $errNo]);
            return [];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('Serper search returned non-2xx', ['http_code' => $httpCode]);
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $organic = $decoded['organic'] ?? [];
        if (!is_array($organic)) {
            return [];
        }

        $results = [];
        foreach ($organic as $item) {
            if (empty($item['link']) || empty($item['title'])) {
                continue;
            }
            $results[] = new SearchResult(
                title: (string) $item['title'],
                url: (string) $item['link'],
                snippet: (string) ($item['snippet'] ?? ''),
                publishedAt: $item['date'] ?? null,
                source: parse_url((string) $item['link'], PHP_URL_HOST) ?: null,
            );
        }

        return $results;
    }
}
