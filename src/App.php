<?php

declare(strict_types=1);

namespace App;

use App\AI\HuggingFaceService;
use App\Content\ContentGenerator;
use App\Content\ContentValidator;
use App\Content\DuplicateDetector;
use App\Content\HistoryRepository;
use App\Content\PostFormatter;
use App\Content\PromptBuilder;
use App\Content\TopicManager;
use App\Scheduler\Scheduler;
use App\Search\SearchServiceFactory;
use App\Storage\JsonStorage;
use App\Support\Logger;
use App\Telegram\TelegramService;

/**
 * App
 *
 * Minimal, explicit service container (no heavy DI framework needed for a
 * project this size). Wires together every component from a single config
 * array. Used by public/cron.php, public/webhook.php and public/index.php
 * so the wiring logic lives in exactly one place.
 */
final class App
{
    public readonly array $config;
    public readonly Logger $logger;
    public readonly TelegramService $telegram;
    public readonly HuggingFaceService $ai;
    public readonly \App\Search\SearchServiceInterface $search;
    public readonly TopicManager $topicManager;
    public readonly DuplicateDetector $duplicateDetector;
    public readonly ContentValidator $validator;
    public readonly PromptBuilder $promptBuilder;
    public readonly ContentGenerator $generator;
    public readonly PostFormatter $formatter;
    public readonly HistoryRepository $history;
    public readonly Scheduler $scheduler;
    public readonly JsonStorage $stateStorage;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->logger = new Logger($config['paths']['log_file'], (string) $config['app']['log_level']);

        $this->telegram = new TelegramService(
            (string) $config['telegram']['bot_token'],
            (string) $config['telegram']['channel_id'],
            (string) $config['telegram']['api_base'],
            (string) $config['telegram']['parse_mode'],
            $this->logger,
        );

        $this->ai = new HuggingFaceService(
            (string) $config['huggingface']['token'],
            (string) $config['huggingface']['model'],
            (string) $config['huggingface']['endpoint'],
            (int) $config['huggingface']['timeout'],
            (int) $config['huggingface']['max_tokens'],
            (float) $config['huggingface']['temperature'],
            $this->logger,
        );

        $this->search = SearchServiceFactory::make($config['search'], $this->logger);

        $this->topicManager = new TopicManager(
            $config['topics']['weights'],
            (int) $config['topics']['recent_window'],
        );

        $this->duplicateDetector = new DuplicateDetector((float) $config['content']['similarity_threshold']);

        $this->validator = new ContentValidator(
            (int) $config['content']['min_words'],
            (int) $config['content']['max_words'],
            (float) $config['content']['min_confidence'],
            (array) $config['content']['extra_banned_phrases'],
        );

        $this->promptBuilder = new PromptBuilder(
            $config['channel'],
            (string) $config['content']['language'],
            (int) $config['content']['min_words'],
            (int) $config['content']['max_words'],
        );

        $this->generator = new ContentGenerator(
            $this->ai,
            $this->search,
            $this->topicManager,
            $this->duplicateDetector,
            $this->validator,
            $this->promptBuilder,
            $this->logger,
            (int) $config['content']['max_generation_retries'],
        );

        $this->formatter = new PostFormatter();

        $historyStorage = new JsonStorage(
            $config['paths']['content_history'],
            ['posts' => []],
            $this->logger,
        );
        $this->history = new HistoryRepository($historyStorage);

        $this->stateStorage = new JsonStorage(
            $config['paths']['state'],
            ['last_post_at' => null, 'next_retry_at' => null, 'auto_publish_paused' => false, 'last_error' => null],
            $this->logger,
        );
        $this->scheduler = new Scheduler(
            $this->stateStorage,
            (int) $config['schedule']['post_interval_hours'],
            (int) $config['schedule']['retry_after_minutes'],
        );
    }

    public static function boot(): self
    {
        $config = require CONFIG_PATH . '/config.php';
        return new self($config);
    }

    public function isAdmin(int $telegramUserId): bool
    {
        return $telegramUserId > 0 && in_array($telegramUserId, $this->config['telegram']['admin_ids'], true);
    }

    /**
     * Decides whether this post should carry a soft CTA line, based on
     * CONTENT_CTA_EVERY (0 = never). Deterministic: the Nth, 2Nth, 3Nth...
     * post (1-indexed) gets a CTA.
     */
    private function shouldIncludeCtaForNextPost(): bool
    {
        $every = (int) $this->config['content']['cta_every'];
        if ($every <= 0) {
            return false;
        }
        $nextPostNumber = $this->history->count() + 1;
        return ($nextPostNumber % $every) === 0;
    }

    /**
     * Runs the full generate -> validate -> publish -> record pipeline.
     * Used by both cron.php and the /generate command.
     *
     * @return array{success: bool, message: string, post?: array}
     */
    public function generateAndMaybePublish(bool $publish, ?string $forceCategory = null): array
    {
        $history = $this->history->all();
        $includeCta = $this->shouldIncludeCtaForNextPost();

        $result = $this->generator->generate($history, $forceCategory, $includeCta);

        if ($result === null) {
            $this->scheduler->recordLastError('Content generation failed after retries (invalid, empty or duplicate output).');
            $this->scheduler->scheduleRetryBackoff();
            return ['success' => false, 'message' => 'تولید محتوا ناموفق بود (بعد از تلاش‌های مجدد). تلاش بعدی طبق زمان backoff انجام می‌شود.'];
        }

        $post = $result['post'];
        $formattedText = $this->formatter->format($post);

        if (!$publish) {
            return [
                'success' => true,
                'message' => 'پیش‌نمایش تولید شد (منتشر نشد چون AUTO_MODE=false یا حالت پیش‌نمایش فعال است).',
                'post' => $post,
                'formatted' => $formattedText,
            ];
        }

        $messageId = $this->telegram->publishToChannel($formattedText);
        if ($messageId === null) {
            $this->scheduler->recordLastError('Telegram publish failed for a validated, generated post.');
            $this->scheduler->scheduleRetryBackoff();
            return ['success' => false, 'message' => 'محتوا تولید شد اما ارسال به تلگرام ناموفق بود.', 'post' => $post];
        }

        $this->history->append($post, $messageId);
        $this->scheduler->recordPublished(date('Y-m-d H:i:s'));

        return [
            'success' => true,
            'message' => 'پست با موفقیت منتشر شد.',
            'post' => $post,
            'formatted' => $formattedText,
            'telegram_message_id' => $messageId,
        ];
    }
}
