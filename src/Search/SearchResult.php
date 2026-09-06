<?php

declare(strict_types=1);

namespace App\Search;

/**
 * SearchResult
 *
 * Immutable value object representing one search result / source, used by
 * ContentGenerator to build the "grounding" context passed to DeepSeek and
 * to populate the "Sources" section of the final post.
 */
final class SearchResult
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly ?string $publishedAt = null,
        public readonly ?string $source = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'published_at' => $this->publishedAt,
            'source' => $this->source,
        ];
    }
}
