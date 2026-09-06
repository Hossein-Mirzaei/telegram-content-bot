<?php

declare(strict_types=1);

namespace App\Telegram;

use App\App;

/**
 * CommandHandler
 *
 * Handles incoming Telegram bot commands received via webhook.php:
 *   /start /status /next /generate /last /stop /help
 *
 * Administrative commands (/generate, /stop, /start-to-resume, etc.) are
 * restricted to the configured TELEGRAM_ADMIN_ID. Any other user gets a
 * polite "not authorized" reply and no further action is taken.
 */
final class CommandHandler
{
    public function __construct(private readonly App $app)
    {
    }

    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        if ($message === null) {
            return; // We only react to private messages to the bot, not channel posts etc.
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null || $text === '') {
            return;
        }

        $isAdmin = $this->app->isAdmin((int) $fromId);

        // Extract the base command (strip @BotUsername and any arguments).
        $command = strtolower(strtok($text, " \n"));
        $command = preg_replace('/@.*$/', '', $command);

        switch ($command) {
            case '/start':
                $this->app->telegram->sendMessage($chatId, $this->welcomeText());
                break;

            case '/help':
                $this->app->telegram->sendMessage($chatId, $this->helpText($isAdmin));
                break;

            case '/status':
                if (!$isAdmin) {
                    $this->deny($chatId);
                    break;
                }
                $this->app->telegram->sendMessage($chatId, $this->statusText());
                break;

            case '/next':
                if (!$isAdmin) {
                    $this->deny($chatId);
                    break;
                }
                $next = $this->app->scheduler->nextPostAt();
                $this->app->telegram->sendMessage($chatId, "⏱ زمان تخمینی پست بعدی: {$next}");
                break;

            case '/last':
                if (!$isAdmin) {
                    $this->deny($chatId);
                    break;
                }
                $this->app->telegram->sendMessage($chatId, $this->lastPostText());
                break;

            case '/generate':
                if (!$isAdmin) {
                    $this->deny($chatId);
                    break;
                }
                $this->app->telegram->sendMessage($chatId, '⏳ در حال تولید محتوا... لطفاً چند ثانیه صبر کن.');
                $autoMode = (bool) $this->app->config['app']['auto_mode'];
                $result = $this->app->generateAndMaybePublish($autoMode);
                $this->app->telegram->sendMessage($chatId, $this->generateResultText($result, $autoMode));
                break;

            case '/stop':
                if (!$isAdmin) {
                    $this->deny($chatId);
                    break;
                }
                $state = $this->app->scheduler->getState();
                $state['auto_publish_paused'] = true;
                $this->app->stateStorage->write($state);
                $this->app->telegram->sendMessage($chatId, '⏸ انتشار خودکار متوقف شد. برای فعال‌سازی مجدد از /start استفاده کن (یا AUTO_MODE را در .env بررسی کن).');
                break;

            default:
                $this->app->telegram->sendMessage($chatId, "دستور ناشناخته است. برای راهنما /help را ارسال کن.");
                break;
        }
    }

    private function deny(int|string $chatId): void
    {
        $this->app->telegram->sendMessage($chatId, '⛔️ شما اجازه استفاده از این دستور را ندارید.');
    }

    private function welcomeText(): string
    {
        $channel = $this->app->config['channel']['url'];
        return "سلام! این ربات محتوای کانال " . "{$channel}" . " را به‌صورت خودکار مدیریت می‌کند.\nبرای دیدن دستورات: /help";
    }

    private function helpText(bool $isAdmin): string
    {
        $lines = [
            '/start - شروع و معرفی ربات',
            '/help - نمایش این راهنما',
        ];
        if ($isAdmin) {
            $lines[] = '/status - وضعیت کامل سیستم';
            $lines[] = '/next - زمان تخمینی پست بعدی';
            $lines[] = '/generate - تولید (و در صورت فعال بودن AUTO_MODE، انتشار) یک پست جدید همین حالا';
            $lines[] = '/last - آخرین پست‌های منتشر شده';
            $lines[] = '/stop - توقف موقت انتشار خودکار';
        }
        return implode("\n", $lines);
    }

    private function statusText(): string
    {
        $state = $this->app->scheduler->getState();
        $historyCount = $this->app->history->count();
        $me = $this->app->telegram->getMe();
        $telegramOk = $me !== null;

        $lastError = $state['last_error']['message'] ?? null;
        $lastErrorAt = $state['last_error']['at'] ?? null;

        $lines = [
            '📊 <b>وضعیت ربات</b>',
            '',
            '🗓 آخرین پست: ' . ($state['last_post_at'] ?? 'هنوز پستی منتشر نشده'),
            '⏭ پست بعدی (تخمینی): ' . $this->app->scheduler->nextPostAt(),
            '📦 تعداد کل پست‌ها: ' . $historyCount,
            '🤖 وضعیت Telegram API: ' . ($telegramOk ? '✅ سالم' : '❌ خطا'),
            '🧠 مدل AI: ' . $this->app->config['huggingface']['model'],
            '🔎 Search Provider: ' . ($this->app->config['search']['enabled'] ? implode(', ', $this->app->config['search']['providers']) : 'غیرفعال'),
            '⚙️ AUTO_MODE: ' . ((bool) $this->app->config['app']['auto_mode'] ? 'فعال' : 'غیرفعال'),
        ];

        if (!empty($state['auto_publish_paused'])) {
            $lines[] = '⏸ انتشار خودکار موقتاً متوقف شده (/stop)';
        }

        if ($lastError) {
            $lines[] = '';
            $lines[] = "❗️ آخرین خطا ({$lastErrorAt}): {$lastError}";
        }

        return implode("\n", $lines);
    }

    private function lastPostText(): string
    {
        $latest = $this->app->history->latest(5);
        if (empty($latest)) {
            return 'هنوز هیچ پستی منتشر نشده است.';
        }

        $lines = ['🗂 <b>آخرین پست‌ها</b>', ''];
        foreach ($latest as $post) {
            $lines[] = sprintf(
                '• [%s] %s (%s)',
                $post['created_at'] ?? '-',
                $post['title'] ?? '-',
                $post['topic_category'] ?? $post['topic'] ?? '-'
            );
        }
        return implode("\n", $lines);
    }

    private function generateResultText(array $result, bool $autoMode): string
    {
        if (!$result['success']) {
            return '❌ ' . $result['message'];
        }

        if (!$autoMode) {
            $preview = $result['formatted'] ?? '';
            return "✅ پیش‌نمایش تولید شد:\n\n" . mb_substr($preview, 0, 3000);
        }

        return '✅ ' . $result['message'];
    }
}
