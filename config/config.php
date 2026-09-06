<?php

/**
 * config/config.php
 *
 * Central configuration file. Reads everything from environment variables
 * (loaded from .env by bootstrap.php) — NOTHING sensitive is hardcoded here.
 * This file must live outside public_html (see README.md "Deployment").
 *
 * Returns a plain PHP array so it can be required anywhere:
 *   $config = require CONFIG_PATH . '/config.php';
 */

declare(strict_types=1);

use App\Support\Env;

return [

    'app' => [
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'timezone' => Env::get('APP_TIMEZONE', 'Asia/Tehran'),
        'auto_mode' => Env::bool('AUTO_MODE', true),
        'log_level' => strtolower((string) Env::get('LOG_LEVEL', 'info')),
    ],

    'channel' => [
        'name' => 'Hossein Mirzaei | Software Engineer',
        'username' => '@Hoseiin_dev',
        'url' => 'https://t.me/Hoseiin_dev',
        'bio' => "Software Engineer | AI Enthusiast\nBuilding projects, learning, and evolving.\nPHP • Python • Backend • AI",
        'links' => [
            'linkedin' => 'https://linkedin.com/in/hossein-mirzaei',
            'github' => 'https://github.com/Hossein-Mirzaei',
            'telegram' => 'https://t.me/Hoseiin_28',
        ],
        // Short, non-pushy call-to-action line, appended only every
        // CONTENT_CTA_EVERY posts (see 'content' section below).
        'cta_text' => 'اگه این نوع مطالب برات مفیده، کانال رو دنبال کن 👋',
    ],

    'telegram' => [
        'bot_token' => Env::get('TELEGRAM_BOT_TOKEN'),
        'channel_id' => Env::get('TELEGRAM_CHANNEL_ID'),
        // Comma-separated list of admin Telegram user IDs, e.g. "111,222"
        'admin_ids' => array_map('intval', Env::list('TELEGRAM_ADMIN_IDS')),
        'webhook_secret' => Env::get('TELEGRAM_WEBHOOK_SECRET', ''),
        'webhook_url' => Env::get('TELEGRAM_WEBHOOK_URL', ''),
        'api_base' => 'https://api.telegram.org/bot',
        'parse_mode' => 'HTML',
    ],

    'huggingface' => [
        'token' => Env::get('HF_TOKEN'),
        'model' => Env::get('HF_MODEL', 'deepseek-ai/DeepSeek-V3'),
        // Hugging Face's OpenAI-compatible router endpoint for chat completions.
        'endpoint' => Env::get('HF_ENDPOINT', 'https://router.huggingface.co/v1/chat/completions'),
        'timeout' => Env::int('HF_TIMEOUT', 120),
        'max_tokens' => Env::int('HF_MAX_TOKENS', 1600),
        'temperature' => Env::float('HF_TEMPERATURE', 0.7),
    ],

    'search' => [
        'enabled' => Env::bool('SEARCH_ENABLED', false),
        // Ordered fallback chain, e.g. "tavily,serper,brave,duckduckgo".
        // Each provider is tried in order until one returns usable results.
        'providers' => Env::list('SEARCH_PROVIDERS', 'none'),
        'keys' => [
            'tavily' => Env::get('SEARCH_TAVILY_KEY', ''),
            'serper' => Env::get('SEARCH_SERPER_KEY', ''),
            'brave' => Env::get('SEARCH_BRAVE_KEY', ''),
        ],
        'max_sources' => Env::int('SEARCH_MAX_SOURCES', 4),
        'max_age_days' => Env::int('SEARCH_MAX_AGE_DAYS', 45),
        'verify_urls' => Env::bool('SEARCH_VERIFY_URLS', true),
        'trusted_domains' => Env::list('SEARCH_TRUSTED_DOMAINS'),
        'blocked_domains' => Env::list('SEARCH_BLOCKED_DOMAINS', 'medium.com,w3schools.com'),
        'timeout' => 15,
    ],

    'topics' => [
        // Relative weights used by TopicManager for weighted-random topic
        // selection. These are only a starting point — TopicManager also
        // dynamically reduces the weight of recently-used categories.
        'weights' => [
            'AI' => 20,
            'Backend' => 12,
            'PHP' => 12,
            'Python' => 12,
            'Software Engineering' => 12,
            'Machine Learning' => 10,
            'LLM' => 8,
            'GitHub' => 6,
            'Databases' => 6,
            'APIs' => 6,
            'Developer Tools' => 8,
            'Tech News' => 6,
            'Cybersecurity' => 5,
        ],
        // How many of the most recent posts to look back at when penalising
        // a category for having been used too recently.
        'recent_window' => 6,
    ],

    'content' => [
        'language' => Env::get('CONTENT_LANGUAGE', 'fa'),
        'min_words' => Env::int('CONTENT_MIN_WORDS', 90),
        'max_words' => Env::int('CONTENT_MAX_WORDS', 300),
        'min_confidence' => Env::float('CONTENT_MIN_CONFIDENCE', 0.5),
        // Add a short CTA line every N published posts (0 = never).
        'cta_every' => Env::int('CONTENT_CTA_EVERY', 4),
        'similarity_threshold' => 0.82, // 0..1, higher = stricter duplicate detection
        'max_generation_retries' => 2,
        // Extra clichéd/forbidden opening phrases on top of the built-in list.
        'extra_banned_phrases' => Env::pipeList('CONTENT_BANNED_PHRASES'),
    ],

    'schedule' => [
        'post_interval_hours' => Env::int('POST_INTERVAL_HOURS', 8),
        'retry_after_minutes' => Env::int('POST_RETRY_AFTER_MINUTES', 45),
        'lock_stale_after_seconds' => Env::int('LOCK_STALE_AFTER_SECONDS', 900),
    ],

    // Shared secret for the URL-based cron trigger (public/cron.php?key=...).
    // Deliberately separate from the Telegram webhook secret.
    'cron_secret' => Env::get('CRON_SECRET', ''),

    'paths' => [
        'content_history' => DATA_PATH . '/content_history.json',
        'state' => DATA_PATH . '/state.json',
        'lock_file' => STORAGE_PATH . '/bot.lock',
        'log_file' => LOG_PATH . '/bot.log',
    ],
];
