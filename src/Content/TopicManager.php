<?php

declare(strict_types=1);

namespace App\Content;

/**
 * TopicManager
 *
 * Selects the category for the next post using weighted-random selection,
 * while actively reducing the weight of categories that were used recently
 * to keep the channel's content diverse (AI, PHP, Python, Backend, LLM,
 * GitHub, Databases, APIs, Developer Tools, Software Engineering,
 * Machine Learning, Tech News, Cybersecurity...).
 */
final class TopicManager
{
    /** @var array<string,int> category => base weight */
    private array $baseWeights;
    private int $recentWindow;

    public function __construct(array $baseWeights, int $recentWindow = 6)
    {
        $this->baseWeights = $baseWeights;
        $this->recentWindow = $recentWindow;
    }

    /**
     * @param array $recentPosts Most-recent-first array of posts, each with a 'topic_category' key.
     * @return string The chosen category.
     */
    public function chooseCategory(array $recentPosts): string
    {
        $recent = array_slice($recentPosts, 0, $this->recentWindow);
        $recentCategories = array_map(
            static fn ($p) => $p['topic_category'] ?? $p['topic'] ?? '',
            $recent
        );

        $weights = $this->baseWeights;

        foreach ($weights as $category => $weight) {
            $occurrences = count(array_filter($recentCategories, static fn ($c) => strcasecmp($c, $category) === 0));
            if ($occurrences > 0) {
                // Exponentially decay the weight for categories used recently.
                // Each recent occurrence roughly halves the chance of being picked again.
                $weights[$category] = max(1, (int) round($weight / (2 ** $occurrences)));
            }

            // Extra penalty if it was literally the very last post's category.
            if (!empty($recentCategories) && strcasecmp($recentCategories[0], $category) === 0) {
                $weights[$category] = max(1, (int) round($weights[$category] * 0.5));
            }
        }

        return $this->weightedRandom($weights);
    }

    /**
     * @param array<string,int> $weights
     */
    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            $keys = array_keys($weights);
            return $keys[array_rand($keys)];
        }

        $rand = random_int(1, $total);
        $cumulative = 0;
        foreach ($weights as $category => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $category;
            }
        }

        // Fallback (should not normally be reached).
        $keys = array_keys($weights);
        return $keys[array_rand($keys)];
    }

    public function categories(): array
    {
        return array_keys($this->baseWeights);
    }
}
