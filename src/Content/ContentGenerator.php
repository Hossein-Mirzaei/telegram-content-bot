<?php

declare(strict_types=1);

namespace App\Content;

use App\AI\HuggingFaceService;
use App\Search\SearchServiceInterface;
use App\Support\Logger;

/**
 * ContentGenerator
 *
 * Orchestrates the full content pipeline described in the project spec:
 *
 *   Search API -> Raw Sources -> Source Validation -> DeepSeek ->
 *   Content Generation -> Duplicate Detection -> Validation -> (caller publishes)
 *
 * This class does NOT talk to Telegram directly — it only produces a
 * validated, de-duplicated post array ready to be formatted and published,
 * or null if no safe/valid post could be produced after retries.
 */
final class ContentGenerator
{
    public function __construct(
        private readonly HuggingFaceService $ai,
        private readonly SearchServiceInterface $search,
        private readonly TopicManager $topicManager,
        private readonly DuplicateDetector $duplicateDetector,
        private readonly ContentValidator $validator,
        private readonly PromptBuilder $promptBuilder,
        private readonly Logger $logger,
        private readonly int $maxRetries = 2,
    ) {
    }

    /**
     * @param array $history Full content_history.json (['posts' => [...]])
     * @param string|null $forceCategory Optional explicit category (used e.g. by /generate with a param), otherwise auto-chosen.
     * @param bool $includeCta Whether to ask the model to append a short CTA line this time (cadence controlled by CONTENT_CTA_EVERY).
     * @return array{post: array, category: string}|null
     */
    public function generate(array $history, ?string $forceCategory = null, bool $includeCta = false): ?array
    {
        $posts = $history['posts'] ?? [];
        $recentPosts = array_slice(array_reverse($posts), 0, 15); // most-recent-first

        $category = $forceCategory ?? $this->topicManager->chooseCategory($recentPosts);

        $avoidTitles = array_values(array_filter(array_map(
            static fn ($p) => $p['title'] ?? null,
            array_slice($recentPosts, 0, 20)
        )));
        $avoidTopics = array_values(array_filter(array_map(
            static fn ($p) => $p['topic'] ?? null,
            array_slice($recentPosts, 0, 20)
        )));

        // --- Search step (optional, provider-dependent) -------------------
        $searchContext = [];
        try {
            $results = $this->search->search($category, 5);
            foreach ($results as $result) {
                // Basic source validation: must have a real, well-formed URL.
                if (!filter_var($result->url, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $searchContext[] = $result->toArray();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Search step failed, continuing without external context', [
                'error' => $e->getMessage(),
            ]);
            $searchContext = [];
        }

        $systemPrompt = $this->promptBuilder->systemPrompt();

        $attempt = 0;
        $lastErrors = [];

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            $userPrompt = $this->promptBuilder->userPrompt($category, $avoidTitles, $avoidTopics, $searchContext, $includeCta);

            $raw = $this->ai->chatCompletion([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ]);

            if ($raw === null) {
                $this->logger->error('DeepSeek returned no response', ['attempt' => $attempt]);
                $lastErrors = ['AI service returned no response'];
                continue;
            }

            $post = $this->parseJson($raw);
            $validation = $this->validator->validate($post);

            if (!$validation['valid']) {
                $this->logger->warning('Generated content failed validation', [
                    'attempt' => $attempt,
                    'errors' => $validation['errors'],
                ]);
                $lastErrors = $validation['errors'];
                continue;
            }

            // At this point $post is a well-formed array.
            if ($this->duplicateDetector->isDuplicate((string) $post['title'], (string) $post['content'], $history)) {
                $this->logger->warning('Generated content flagged as duplicate, retrying', [
                    'attempt' => $attempt,
                    'title' => $post['title'],
                ]);
                $lastErrors = ['duplicate content'];
                continue;
            }

            $post['topic_category'] = $category;
            return ['post' => $post, 'category' => $category];
        }

        $this->logger->error('Failed to generate a valid, non-duplicate post after retries', [
            'category' => $category,
            'last_errors' => $lastErrors,
        ]);

        return null;
    }

    private function parseJson(string $raw): ?array
    {
        $raw = trim($raw);

        // Defensive cleanup in case the model wraps JSON in a markdown fence
        // despite instructions not to.
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-zA-Z]*\n/', '', $raw);
            $raw = preg_replace('/```$/', '', $raw);
            $raw = trim($raw);
        }

        // If there is leading/trailing text around the JSON object, try to
        // extract the outermost {...} block.
        if (!str_starts_with($raw, '{')) {
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $raw = substr($raw, $start, $end - $start + 1);
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }
}
