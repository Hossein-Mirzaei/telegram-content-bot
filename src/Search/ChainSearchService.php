<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * ChainSearchService
 *
 * Implements the SEARCH_PROVIDERS fallback chain (e.g. "tavily,serper,brave,duckduckgo"):
 * tries each configured provider in order and returns the first one that
 * yields at least one result surviving SearchResultFilter (domain
 * allow/block list, max-age, optional URL verification). If every provider
 * fails or returns nothing usable, an empty array is returned so the
 * content pipeline gracefully falls back to evergreen/no-source content
 * instead of crashing or blocking publication.
 */
final class ChainSearchService implements SearchServiceInterface
{
    /** @param SearchServiceInterface[] $providers Ordered list, first = highest priority. */
    public function __construct(
        private readonly array $providers,
        private readonly SearchResultFilter $filter,
        private readonly Logger $logger,
    ) {
    }

    public function search(string $query, int $maxResults = 5): array
    {
        foreach ($this->providers as $provider) {
            try {
                $raw = $provider->search($query, $maxResults);
            } catch (\Throwable $e) {
                $this->logger->error('Search provider threw an exception, trying next provider', [
                    'provider' => get_class($provider),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (empty($raw)) {
                continue;
            }

            $filtered = $this->filter->apply($raw);
            if (!empty($filtered)) {
                return $filtered;
            }

            $this->logger->info('Search provider returned results but none survived filtering, trying next provider', [
                'provider' => get_class($provider),
            ]);
        }

        return [];
    }
}
