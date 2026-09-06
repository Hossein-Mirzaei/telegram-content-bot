<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * SearchResultFilter
 *
 * Applies the "Source Validation" step of the pipeline described in the
 * project spec (Search API -> Raw Sources -> Source Validation -> DeepSeek):
 *   - Drops results from explicitly blocked domains.
 *   - If a trusted-domain allow-list is configured, keeps only those.
 *   - Drops results older than SEARCH_MAX_AGE_DAYS (when a publish date is known).
 *   - Optionally verifies each URL actually responds (HTTP HEAD) before it
 *     is allowed to be cited, to catch dead/fabricated-looking links.
 *   - Truncates to SEARCH_MAX_SOURCES.
 */
final class SearchResultFilter
{
    public function __construct(
        private readonly array $trustedDomains,
        private readonly array $blockedDomains,
        private readonly int $maxAgeDays,
        private readonly bool $verifyUrls,
        private readonly int $maxSources,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param SearchResult[] $results
     * @return SearchResult[]
     */
    public function apply(array $results): array
    {
        $filtered = [];

        foreach ($results as $result) {
            if (!filter_var($result->url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $host = strtolower((string) (parse_url($result->url, PHP_URL_HOST) ?? ''));
            if ($host === '') {
                continue;
            }

            if ($this->isBlockedDomain($host)) {
                continue;
            }

            if (!empty($this->trustedDomains) && !$this->isTrustedDomain($host)) {
                continue;
            }

            if (!$this->isFreshEnough($result->publishedAt)) {
                continue;
            }

            if ($this->verifyUrls && !$this->urlResponds($result->url)) {
                $this->logger->warning('Dropping source that failed URL verification', ['url' => $result->url]);
                continue;
            }

            $filtered[] = $result;

            if (count($filtered) >= $this->maxSources) {
                break;
            }
        }

        return $filtered;
    }

    private function isBlockedDomain(string $host): bool
    {
        foreach ($this->blockedDomains as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($blocked !== '' && (str_contains($host, $blocked))) {
                return true;
            }
        }
        return false;
    }

    private function isTrustedDomain(string $host): bool
    {
        foreach ($this->trustedDomains as $trusted) {
            $trusted = strtolower(trim($trusted));
            if ($trusted !== '' && str_contains($host, $trusted)) {
                return true;
            }
        }
        return false;
    }

    private function isFreshEnough(?string $publishedAt): bool
    {
        if ($this->maxAgeDays <= 0 || $publishedAt === null || $publishedAt === '') {
            // No age limit configured, or the provider didn't give us a date
            // (we don't want to reject a result just because the date is
            // unknown — DeepSeek is separately instructed to be cautious
            // with undated information via the "confidence" field).
            return true;
        }

        $timestamp = strtotime($publishedAt);
        if ($timestamp === false) {
            return true;
        }

        $ageDays = (time() - $timestamp) / 86400;
        return $ageDays <= $this->maxAgeDays;
    }

    private function urlResponds(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ContentBot/1.0; +https://t.me/Hoseiin_dev)',
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        curl_close($ch);

        if ($errNo !== 0) {
            return false;
        }

        return $httpCode >= 200 && $httpCode < 400;
    }
}
