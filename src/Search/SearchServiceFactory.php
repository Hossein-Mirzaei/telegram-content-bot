<?php

declare(strict_types=1);

namespace App\Search;

use App\Support\Logger;

/**
 * SearchServiceFactory
 *
 * Builds the SearchServiceInterface used by ContentGenerator from the
 * 'search' config section (SEARCH_ENABLED, SEARCH_PROVIDERS, per-provider
 * keys, and source-validation settings). SEARCH_PROVIDERS is an ordered
 * fallback chain, e.g. "tavily,serper,brave,duckduckgo".
 *
 * To add a new provider (Bing, Google News via SerpAPI, ...):
 *   1. Create a class implementing SearchServiceInterface.
 *   2. Add a case for it in the match() below.
 *   3. Add its name to SEARCH_PROVIDERS in .env.
 * Nothing else in the codebase needs to change.
 */
final class SearchServiceFactory
{
    public static function make(array $searchConfig, Logger $logger): SearchServiceInterface
    {
        if (empty($searchConfig['enabled'])) {
            return new NullSearchService();
        }

        $timeout = (int) ($searchConfig['timeout'] ?? 15);
        $keys = $searchConfig['keys'] ?? [];
        $providerNames = $searchConfig['providers'] ?? [];

        $providers = [];
        foreach ($providerNames as $name) {
            $name = strtolower(trim($name));
            $instance = match ($name) {
                'tavily' => new TavilySearchService((string) ($keys['tavily'] ?? ''), $timeout, $logger),
                'serper' => new SerperSearchService((string) ($keys['serper'] ?? ''), $timeout, $logger),
                'brave' => new BraveSearchService((string) ($keys['brave'] ?? ''), $timeout, $logger),
                'duckduckgo', 'ddg' => new DuckDuckGoSearchService($timeout, $logger),
                'none' => null,
                default => null,
            };

            if ($instance !== null) {
                $providers[] = $instance;
            } else {
                $logger->warning('Unknown or disabled search provider in SEARCH_PROVIDERS, skipping', ['provider' => $name]);
            }
        }

        if (empty($providers)) {
            return new NullSearchService();
        }

        $filter = new SearchResultFilter(
            trustedDomains: $searchConfig['trusted_domains'] ?? [],
            blockedDomains: $searchConfig['blocked_domains'] ?? [],
            maxAgeDays: (int) ($searchConfig['max_age_days'] ?? 45),
            verifyUrls: (bool) ($searchConfig['verify_urls'] ?? true),
            maxSources: (int) ($searchConfig['max_sources'] ?? 4),
            logger: $logger,
        );

        return new ChainSearchService($providers, $filter, $logger);
    }
}
