<?php

declare(strict_types=1);

namespace App\Search;

/**
 * SearchServiceInterface
 *
 * Contract every search provider must implement. This lets ContentGenerator
 * stay completely decoupled from which provider is actually used. Adding a
 * new provider (Google News, Bing, Tavily, Serper, Brave, ...) means writing
 * one new class that implements this interface and registering it in
 * SearchServiceFactory — nothing else in the codebase needs to change.
 *
 * @see SearchResult
 */
interface SearchServiceInterface
{
    /**
     * Search for recent, relevant information about a topic/query.
     *
     * @param string $query
     * @param int $maxResults
     * @return SearchResult[] Empty array if nothing found or on failure.
     *                        Implementations must NEVER throw for network/API
     *                        errors — they must log and return [].
     */
    public function search(string $query, int $maxResults = 5): array;
}
