<?php

declare(strict_types=1);

namespace App\Content;

/**
 * DuplicateDetector
 *
 * Prevents publishing near-duplicate content by comparing a candidate post
 * against the stored history using three complementary checks:
 *   1. Exact/near-exact title match (normalized string comparison).
 *   2. Exact content hash match (sha256 of normalized body text).
 *   3. Fuzzy similarity (PHP's similar_text percentage) against recent posts'
 *      bodies, to catch reworded-but-essentially-identical content.
 */
final class DuplicateDetector
{
    private float $similarityThreshold;

    public function __construct(float $similarityThreshold = 0.82)
    {
        $this->similarityThreshold = $similarityThreshold;
    }

    /**
     * @param array $history Full content_history.json ['posts' => [...]]
     */
    public function isDuplicate(string $title, string $content, array $history): bool
    {
        $posts = $history['posts'] ?? [];
        if (empty($posts)) {
            return false;
        }

        $normalizedTitle = $this->normalize($title);
        $contentHash = $this->hash($content);

        foreach ($posts as $post) {
            $existingTitle = $this->normalize((string) ($post['title'] ?? ''));
            $existingHash = (string) ($post['content_hash'] ?? '');

            // 1. Exact hash match.
            if ($existingHash !== '' && $existingHash === $contentHash) {
                return true;
            }

            // 2. Title near-match.
            if ($existingTitle !== '' && $this->similarity($existingTitle, $normalizedTitle) >= 0.90) {
                return true;
            }

            // 3. Fuzzy body similarity (only worth checking if we still have the body stored).
            $existingContent = (string) ($post['content'] ?? '');
            if ($existingContent !== '') {
                $sim = $this->similarity($this->normalize($existingContent), $this->normalize($content));
                if ($sim >= $this->similarityThreshold) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hash(string $content): string
    {
        return hash('sha256', $this->normalize($content));
    }

    /**
     * Returns a 0..1 similarity ratio between two strings.
     */
    public function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        similar_text($a, $b, $percent);
        return $percent / 100;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
