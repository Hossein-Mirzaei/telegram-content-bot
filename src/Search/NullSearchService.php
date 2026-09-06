<?php

declare(strict_types=1);

namespace App\Search;

/**
 * NullSearchService
 *
 * Default provider when no web-search API key/provider is configured
 * (SEARCH_PROVIDER=none). Always returns an empty result set. In this mode,
 * ContentGenerator relies on evergreen/educational topics (concepts, code
 * tips, tool overviews) rather than breaking-news items, since there is no
 * way to verify current information without a real search provider.
 */
final class NullSearchService implements SearchServiceInterface
{
    public function search(string $query, int $maxResults = 5): array
    {
        return [];
    }
}
