<?php

declare(strict_types=1);

namespace App\Content;

/**
 * ContentValidator
 *
 * Validates the JSON payload returned by DeepSeek before it is ever
 * published. Catches: missing fields, too-short/too-long content (measured
 * in words, since content is primarily Persian and word count is a more
 * meaningful signal than raw character count), leaked meta-commentary
 * ("Here is your post", clichéd Persian openings, ...), low self-reported
 * confidence (used as an anti-fake-news guard for news-style topics), and
 * invalid/fabricated-looking sources.
 */
final class ContentValidator
{
    private int $minWords;
    private int $maxWords;
    private float $minConfidence;

    /** Phrases that indicate the model leaked meta-commentary instead of pure content. */
    private array $forbiddenPhrases = [
        'here is your post',
        "here's your post",
        'here is the post',
        'sure, here',
        'as an ai',
        'i cannot browse',
        'as a language model',
        // Common clichéd Persian openings the system prompt explicitly bans.
        'در دنیای امروز',
        'با پیشرفت روزافزون',
        'هوش مصنوعی انقلابی',
    ];

    /**
     * @param string[] $extraBannedPhrases Additional phrases from CONTENT_BANNED_PHRASES (env)
     */
    public function __construct(
        int $minWords = 90,
        int $maxWords = 300,
        float $minConfidence = 0.5,
        array $extraBannedPhrases = [],
    ) {
        $this->minWords = $minWords;
        $this->maxWords = $maxWords;
        $this->minConfidence = $minConfidence;
        foreach ($extraBannedPhrases as $phrase) {
            $phrase = trim($phrase);
            if ($phrase !== '') {
                $this->forbiddenPhrases[] = mb_strtolower($phrase, 'UTF-8');
            }
        }
    }

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(?array $post): array
    {
        $errors = [];

        if ($post === null) {
            return ['valid' => false, 'errors' => ['payload is not valid JSON']];
        }

        foreach (['topic', 'title', 'content', 'hashtags'] as $field) {
            if (!array_key_exists($field, $post) || $post[$field] === null || $post[$field] === '') {
                $errors[] = "missing required field: {$field}";
            }
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        $content = (string) $post['content'];
        $wordCount = $this->countWords($content);

        if ($wordCount < $this->minWords) {
            $errors[] = "content too short ({$wordCount} words, minimum {$this->minWords})";
        }

        if ($wordCount > $this->maxWords) {
            $errors[] = "content too long ({$wordCount} words, maximum {$this->maxWords})";
        }

        $lowerContent = mb_strtolower($content, 'UTF-8');
        foreach ($this->forbiddenPhrases as $phrase) {
            if ($phrase !== '' && str_contains($lowerContent, $phrase)) {
                $errors[] = "content contains forbidden/clichéd phrase: \"{$phrase}\"";
            }
        }

        if (!is_array($post['hashtags']) || count($post['hashtags']) === 0) {
            $errors[] = 'hashtags must be a non-empty array';
        } elseif (count($post['hashtags']) > 8) {
            $errors[] = 'too many hashtags (keep it to roughly 3-6)';
        }

        if (isset($post['sources']) && !is_array($post['sources'])) {
            $errors[] = 'sources must be an array when present';
        }

        if (!empty($post['sources'])) {
            foreach ($post['sources'] as $i => $source) {
                if (empty($source['url']) || !filter_var($source['url'], FILTER_VALIDATE_URL)) {
                    $errors[] = "source #{$i} has a missing or invalid URL";
                }
                if (empty($source['title'])) {
                    $errors[] = "source #{$i} is missing a title";
                }
            }
        }

        // Anti-fake-news guard: DeepSeek is asked to self-report a confidence
        // score (0..1) reflecting how certain it is that the content/claims
        // are accurate and current. Below the configured threshold, we
        // reject the post rather than risk publishing shaky information.
        if (array_key_exists('confidence', $post)) {
            $confidence = $post['confidence'];
            if (!is_numeric($confidence)) {
                $errors[] = 'confidence field must be numeric when present';
            } elseif ((float) $confidence < $this->minConfidence) {
                $errors[] = sprintf(
                    'confidence too low (%.2f < %.2f threshold) - content rejected as a fake-news precaution',
                    (float) $confidence,
                    $this->minConfidence
                );
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function countWords(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        // Works for both Persian and Latin scripts: split on any whitespace run.
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? 0 : count($words);
    }
}
