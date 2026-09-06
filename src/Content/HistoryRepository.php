<?php

declare(strict_types=1);

namespace App\Content;

use App\Storage\JsonStorage;

/**
 * HistoryRepository
 *
 * Thin domain wrapper around JsonStorage for data/content_history.json.
 * Handles auto-incrementing IDs and appending a newly published post record.
 */
final class HistoryRepository
{
    public function __construct(private readonly JsonStorage $storage)
    {
    }

    public function all(): array
    {
        $data = $this->storage->read();
        if (!isset($data['posts']) || !is_array($data['posts'])) {
            $data['posts'] = [];
        }
        return $data;
    }

    public function count(): int
    {
        return count($this->all()['posts']);
    }

    public function append(array $post, ?int $telegramMessageId): bool
    {
        $data = $this->all();
        $nextId = 1;
        foreach ($data['posts'] as $p) {
            if (isset($p['id']) && (int) $p['id'] >= $nextId) {
                $nextId = (int) $p['id'] + 1;
            }
        }

        $data['posts'][] = [
            'id' => $nextId,
            'created_at' => date('Y-m-d H:i:s'),
            'topic' => $post['topic'] ?? '',
            'topic_category' => $post['topic_category'] ?? '',
            'title' => $post['title'] ?? '',
            'content' => $post['content'] ?? '',
            'content_hash' => hash('sha256', mb_strtolower(trim((string) ($post['content'] ?? '')))),
            'hashtags' => $post['hashtags'] ?? [],
            'confidence' => $post['confidence'] ?? null,
            'source_urls' => array_values(array_filter(array_map(
                static fn ($s) => $s['url'] ?? null,
                $post['sources'] ?? []
            ))),
            'telegram_message_id' => $telegramMessageId,
        ];

        return $this->storage->write($data);
    }

    public function latest(int $limit = 10): array
    {
        $posts = $this->all()['posts'];
        return array_slice(array_reverse($posts), 0, $limit);
    }
}
