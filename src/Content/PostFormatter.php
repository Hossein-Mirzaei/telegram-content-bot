<?php

declare(strict_types=1);

namespace App\Content;

/**
 * PostFormatter
 *
 * Turns a validated post array (topic/title/content/hashtags/sources) into
 * the final HTML-formatted text sent to Telegram (parse_mode=HTML). Escapes
 * user/AI-provided text to avoid breaking Telegram's HTML parser.
 */
final class PostFormatter
{
    public function format(array $post): string
    {
        $content = trim((string) $post['content']);
        // The model is asked to already include 🚀/📌/💡 section markers inside
        // "content" — we just HTML-escape the free text portions safely while
        // leaving simple ```code``` fences convertible to <pre> blocks.
        $content = $this->convertCodeFences($content);

        $parts = [$content];

        if (!empty($post['sources']) && is_array($post['sources'])) {
            $sourceLines = [];
            $i = 1;
            foreach ($post['sources'] as $source) {
                if (empty($source['url'])) {
                    continue;
                }
                $title = $this->escape((string) ($source['title'] ?? $source['url']));
                $url = $this->escapeAttribute((string) $source['url']);
                $sourceLines[] = "{$i}. <a href=\"{$url}\">{$title}</a>";
                $i++;
            }
            if (!empty($sourceLines)) {
                $parts[] = "🔗 Sources\n" . implode("\n", $sourceLines);
            }
        }

        if (!empty($post['hashtags']) && is_array($post['hashtags'])) {
            $tags = array_map(function ($tag) {
                $tag = trim((string) $tag);
                if ($tag === '') {
                    return null;
                }
                if ($tag[0] !== '#') {
                    $tag = '#' . $tag;
                }
                // Hashtags must not contain spaces/HTML-sensitive chars.
                $tag = preg_replace('/[^#\p{L}\p{N}_]/u', '', $tag);
                return $tag !== '' ? $tag : null;
            }, $post['hashtags']);
            $tags = array_values(array_filter($tags));
            $tags = array_slice($tags, 0, 6);
            if (!empty($tags)) {
                $parts[] = implode(' ', $tags);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Escapes plain text for Telegram HTML parse mode, but this method is
     * only used for text WE construct (titles, source names). The main
     * "content" body coming from DeepSeek is expected to be plain text with
     * emoji and optional ```code``` fences (converted separately) — DeepSeek
     * is instructed not to output raw HTML.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Converts ```lang\ncode\n``` fenced blocks into Telegram <pre><code> blocks,
     * and escapes everything else so stray < > & from the model can't break
     * Telegram's HTML parser.
     */
    private function convertCodeFences(string $text): string
    {
        $pattern = '/```[a-zA-Z0-9_+-]*\n(.*?)```/s';

        // First, protect code blocks by extracting them.
        $blocks = [];
        $withPlaceholders = preg_replace_callback($pattern, function ($m) use (&$blocks) {
            $index = count($blocks);
            $blocks[$index] = $m[1];
            return "\0CODEBLOCK{$index}\0";
        }, $text);

        // Escape the remaining plain text.
        $escaped = htmlspecialchars($withPlaceholders, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Re-insert escaped code inside <pre><code> tags.
        $escaped = preg_replace_callback('/\0CODEBLOCK(\d+)\0/', function ($m) use ($blocks) {
            $code = $blocks[(int) $m[1]] ?? '';
            $code = htmlspecialchars(rtrim($code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<pre><code>{$code}</code></pre>";
        }, $escaped);

        return $escaped;
    }
}
