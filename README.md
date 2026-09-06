# 🤖 Telegram Content Bot

![Bot Screenshot](1.png)

![Channel Preview](2.png)

Automated content generation and publishing bot for Telegram channels, built with
**PHP 8.2+**, database-free, and suitable for any standard shared hosting.

Every 8 hours, the bot automatically:
Selects a topic → Prevents duplicates → (Optional) Gathers fresh info via Search Providers
→ Generates professional Persian content via **Hugging Face Inference API** with **DeepSeek** model
→ Validates output → Publishes to channel → Saves to JSON file.

> ⚠️ **Important:** The `.env` file contains sensitive tokens and should never be committed to GitHub.
> Use `.env.example` as a template and keep the real `.env` only on your hosting server.

---

## Features

- ✅ Automated content generation with AI (DeepSeek/Hugging Face)
- ✅ Auto-publishing to Telegram channels
- ✅ Duplicate content prevention
- ✅ Configurable scheduling (default: every 8 hours)
- ✅ Content validation before publishing
- ✅ Search Provider chain (Tavily, Serper, Brave, DuckDuckGo)
- ✅ Database-free (JSON-based storage)
- ✅ Shared hosting friendly
- ✅ Webhook and Cron Job support

## Architecture

```
Cron (every 15 min) ──▶ Has 8 hours passed + backoff expired? ──▶ No ──▶ Silent exit
                                   │
                                  Yes
                                   ▼
                        TopicManager (weighted-random category selection)
                                   ▼
        ChainSearchService: Tavily → Serper → Brave → DuckDuckGo (first valid result)
                                   ▼
     SearchResultFilter (blocked/trusted domains, max age, URL liveness check)
                                   ▼
                HuggingFaceService  →  DeepSeek model (Persian JSON output only)
                                   ▼
        ContentValidator (fields, word count, banned phrases, confidence)
                                   ▼
                     DuplicateDetector (title + hash + fuzzy similarity)
                                   ▼
                PostFormatter (🚀📌💡 structure + numbered Sources + hashtags + periodic CTA)
                                   ▼
                      TelegramService (sendMessage to channel)
                                   ▼
                 HistoryRepository → data/content_history.json
                 Scheduler         → data/state.json (last_post_at / next_retry_at)
```

If generation or publishing fails, `Scheduler` registers a **backoff** (default 45 minutes,
`POST_RETRY_AFTER_MINUTES`) so Cron doesn't relentlessly hit the API every 15 minutes;
after backoff expires, the next attempt runs independently of the 8-hour cycle.

---

## File Structure

```
telegram-content-bot/                ← Place this folder outside public_html
├── .env                             ← Your actual values (NEVER commit)
├── .env.example                     ← Sanitized version for reference/rotation
├── .htaccess                        ← Extra defense if placed in web root
├── bootstrap.php                    ← Common bootstrap for all entry points
├── composer.json                    ← Optional PSR-4 autoload only
├── config/
│   └── config.php                   ← All settings, read from .env
├── data/
│   ├── content_history.json
│   ├── state.json
│   └── .htaccess                    ← Deny all
├── logs/
│   ├── bot.log
│   ├── php_errors.log
│   └── .htaccess                    ← Deny all
├── storage/
│   ├── bot.lock                     ← Created at runtime
│   └── .htaccess                    ← Deny all
├── tools/
│   ├── setup_webhook.php            ← CLI tool to set/remove Webhook
│   └── .htaccess                    ← Deny all (CLI only)
├── src/
│   ├── App.php                      ← Service Container / wiring
│   ├── AI/HuggingFaceService.php
│   ├── Content/
│   │   ├── ContentGenerator.php
│   │   ├── ContentValidator.php
│   │   ├── DuplicateDetector.php
│   │   ├── HistoryRepository.php
│   │   ├── PostFormatter.php
│   │   ├── PromptBuilder.php
│   │   └── TopicManager.php
│   ├── Scheduler/Scheduler.php
│   ├── Search/
│   │   ├── SearchServiceInterface.php
│   │   ├── SearchServiceFactory.php
│   │   ├── SearchResult.php
│   │   ├── SearchResultFilter.php
│   │   ├── ChainSearchService.php
│   │   ├── NullSearchService.php
│   │   ├── TavilySearchService.php
│   │   ├── SerperSearchService.php
│   │   ├── BraveSearchService.php
│   │   └── DuckDuckGoSearchService.php
│   ├── Storage/JsonStorage.php
│   ├── Support/{Env,Logger,Lock}.php
│   └── Telegram/{TelegramService,CommandHandler}.php
└── public/                          ← Only this folder goes in public_html
    ├── .htaccess
    ├── index.php
    ├── cron.php
    └── webhook.php
```

---

## Requirements

* PHP **8.2+** with extensions `curl`, `mbstring`, `json`, and `dom`/`xml`
  (enabled by default on most shared hosts; `dom` is only needed for the free
  DuckDuckGo fallback — if missing, the bot won't crash, it just ignores that provider).
* Cron Job access (most cPanel/DirectAdmin hosts have this).
* A Telegram bot (from [@BotFather](https://t.me/BotFather)).
* Hugging Face API Token.
* (Optional) Tavily and/or Serper keys for web search.

---

## Installation

### Step 1 — Upload Files

**Recommended structure (most secure):**

```
/home/USERNAME/                        ← Outside public_html
    telegram-content-bot/               ← Entire project except public/ folder
        .env  bootstrap.php  config/  src/  data/  logs/  storage/  tools/

/home/USERNAME/public_html/
    telegram-content-bot/               ← Only contents of public/ folder
        index.php  cron.php  webhook.php  .htaccess
```

If using this structure, update the require line in `public/index.php`,
`public/cron.php` and `public/webhook.php` from:

```php
require_once __DIR__ . '/../bootstrap.php';
```

to the actual path relative to the new `bootstrap.php` location, e.g.:

```php
require_once '/home/USERNAME/telegram-content-bot/bootstrap.php';
```

**If your host doesn't allow leaving public_html (simpler approach):**

Upload the entire project folder as-is, e.g. inside
`public_html/telegram-content-bot/` (exactly the path assumed in
`TELEGRAM_WEBHOOK_URL` in `.env`:
`https://your-domain.example/telegram-content-bot/webhook.php`). The `.htaccess`
files inside `data/`, `logs/`, `storage/`, `tools/` and the project root
prevent direct browser access to sensitive files, and no path changes in the
require statements are needed.

### Step 2 — Configure `.env`

Copy `.env.example` to `.env` and fill in the values:

* `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHANNEL_ID=@YourChannel`, `TELEGRAM_ADMIN_IDS`
* `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_WEBHOOK_URL`
* `HF_TOKEN`, `HF_MODEL=deepseek-ai/DeepSeek-V3`
* `SEARCH_ENABLED=true` with provider chain `tavily,serper,brave,duckduckgo`
* `CRON_SECRET`

Just verify that `TELEGRAM_ADMIN_IDS` is your numeric user ID (from
[@userinfobot](https://t.me/userinfobot)) — it currently has one ID, if you want
multiple admins separate with commas: `111,222`.

> If your hosting panel supports setting PHP-FPM/cPanel-level environment variables,
> you can use those instead — `Env::get()` supports both, and real server-level
> variables always take precedence.

### Step 3 — Add Bot to Channel as Admin

Add the bot to your channel `https://t.me/YourChannel` and make it an Admin
(at minimum **Post Messages** permission).

### Step 4 — Set Up Cron Job

In cPanel → Cron Jobs, create a job running every 15 minutes:

```
*/15 * * * * /usr/bin/php /home/USERNAME/telegram-content-bot/public/cron.php >> /home/USERNAME/telegram-content-bot/logs/cron_stdout.log 2>&1
```

(Check the `php` path with `which php` on your host — sometimes it's
`/usr/local/bin/php`.)

If your host only supports URL-based cron (`wget`/`curl` jobs):

```
*/15 * * * * curl -s "https://your-domain.example/telegram-content-bot/cron.php?key=YOUR_CRON_SECRET" > /dev/null
```

(The `key` value is the `CRON_SECRET` from `.env`.)

### Step 5 — Set Up Webhook (optional, for bot commands like /status)

From the host, via SSH or the panel's terminal, run once:

```bash
php tools/setup_webhook.php set
```

This command automatically registers the webhook using `TELEGRAM_WEBHOOK_URL`
and `TELEGRAM_WEBHOOK_SECRET` from `.env`. To check status:

```bash
php tools/setup_webhook.php info
```

And to remove (revert to cron-only mode):

```bash
php tools/setup_webhook.php delete
```

If you don't have SSH, the webhook is optional — auto-publishing works
perfectly with just Cron.

---

## Environment Variables (`.env`)

| Variable | Description |
|---|---|
| `APP_TIMEZONE` | e.g. `Asia/Tehran` |
| `APP_DEBUG` | Keep `false` for now |
| `AUTO_MODE` | `true` = auto-generate + publish / `false` = generate + preview only |
| `LOG_LEVEL` | `debug` / `info` / `warning` / `error` |
| `BOT_DATA_DIR` / `BOT_LOG_DIR` / `BOT_STORAGE_DIR` | (Optional) move runtime directories outside the project |
| `BOT_SECRETS_FILE` | (Optional) separate secrets file (`.php` or `.env`) outside the project |
| `TELEGRAM_BOT_TOKEN` | Bot token from BotFather |
| `TELEGRAM_CHANNEL_ID` | Channel username (@YourChannel) or numeric chat ID |
| `TELEGRAM_ADMIN_IDS` | Admin Telegram user IDs, comma-separated |
| `TELEGRAM_WEBHOOK_SECRET` | Random string for webhook verification |
| `TELEGRAM_WEBHOOK_URL` | Full URL to webhook.php on host (for `tools/setup_webhook.php`) |
| `HF_TOKEN` | Hugging Face token |
| `HF_MODEL` | Model name (default DeepSeek-V3) |
| `HF_ENDPOINT` | Hugging Face chat completion router endpoint |
| `HF_TEMPERATURE` / `HF_MAX_TOKENS` / `HF_TIMEOUT` | AI request parameters |
| `SEARCH_ENABLED` | Enable/disable the entire search phase |
| `SEARCH_PROVIDERS` | Fallback chain, e.g. `tavily,serper,brave,duckduckgo` |
| `SEARCH_TAVILY_KEY` / `SEARCH_SERPER_KEY` / `SEARCH_BRAVE_KEY` | Each provider's API key |
| `SEARCH_MAX_SOURCES` | Maximum number of sources per post |
| `SEARCH_MAX_AGE_DAYS` | Skip results older than N days (when date available) |
| `SEARCH_VERIFY_URLS` | Verify URL liveness with HEAD request before accepting source |
| `SEARCH_TRUSTED_DOMAINS` / `SEARCH_BLOCKED_DOMAINS` | Whitelist/blacklist domains, comma-separated |
| `CONTENT_LANGUAGE` | `fa` (Persian) or `en` |
| `CONTENT_MIN_WORDS` / `CONTENT_MAX_WORDS` | Post length range by word count |
| `CONTENT_MIN_CONFIDENCE` | Minimum model `confidence` for publishing (fake news guard) |
| `CONTENT_CTA_EVERY` | Add CTA every N posts (0 = never) |
| `CONTENT_BANNED_PHRASES` | Additional forbidden openings, separated by `|` |
| `POST_INTERVAL_HOURS` | Publishing interval (default 8 hours) |
| `POST_RETRY_AFTER_MINUTES` | Backoff duration after failure |
| `LOCK_STALE_AFTER_SECONDS` | Time after which a stale lock from a crashed process auto-releases |
| `CRON_SECRET` | For URL-based cron trigger: `cron.php?key=...` |

---

## Post Format

Every post follows this structure (exactly as requested):

```
🚀 Attractive, specific title

Short intro (1–3 sentences)

📌 Main technical explanation, with short paragraphs. If needed, a short code block:

```
example code
```

And a short sentence below the code explaining it.

💡 Why it matters?
One paragraph about why this topic matters to developers.

🔗 Sources
1. source title (clickable link)
2. second source title (clickable link)

#hashtag1 #hashtag2 #hashtag3
```

Notes:

* The "🚀 → 📌 → 💡 Why it matters?" structure is generated by DeepSeek itself
  (per the System Prompt in `PromptBuilder.php`).
* The "🔗 Sources" section and hashtags are NOT written by the model — these come
  from separate JSON fields (`sources`, `hashtags`) and are assembled by
  `PostFormatter.php` to ensure a consistent format.
* The CTA line ("اگه این نوع مطالب برات مفیده...") is only automatically requested
  every `CONTENT_CTA_EVERY` posts (default every 4 posts) — not in every post.
* The model must also return a `confidence` number between 0 and 1 indicating its
  certainty about the claims' accuracy/validity; if below `CONTENT_MIN_CONFIDENCE`,
  the post is not published (the main guard against fake news, per the original
  project requirement section 23).

---

## Bot Commands

| Command | Access | Description |
|---|---|---|
| `/start` | Everyone | Bot introduction |
| `/help` | Everyone | Help text |
| `/status` | Admin only | Full system status |
| `/next` | Admin only | Estimated next post time |
| `/generate` | Admin only | Immediately generate (and if AUTO_MODE is true, publish) a post |
| `/last` | Admin only | Last 5 published posts |
| `/stop` | Admin only | Temporarily pause auto-publishing |

Administrative commands only respond to user IDs in `TELEGRAM_ADMIN_IDS`.

---

## Adding a New Search Provider

1. Create a class like `src/Search/BingSearchService.php` that implements
   `SearchServiceInterface` (use `SerperSearchService.php` as a template).
2. Add a new `case` in `src/Search/SearchServiceFactory.php`.
3. In `.env`, add its name to the `SEARCH_PROVIDERS` list and set its API key.

No other files need changes — `ChainSearchService` manages the fallback chain
automatically.

---

## Tests

### Test Hugging Face Connection

```bash
php -r '
require "/home/USERNAME/telegram-content-bot/bootstrap.php";
$app = App\App::boot();
$response = $app->ai->chatCompletion([["role"=>"user","content"=>"Say OK"]]);
var_dump($response);
'
```

### Test Telegram Connection

```bash
php -r '
require "/home/USERNAME/telegram-content-bot/bootstrap.php";
$app = App\App::boot();
var_dump($app->telegram->getMe());
'
```

### Test Content Generation Without Publishing

Set `AUTO_MODE=false` and send `/generate` via Telegram — you'll receive a
preview message and nothing gets published to the channel.

### Test Duplicate Detection

Run `/generate` with `AUTO_MODE=true`, wait for the post to be saved, then try
again and look for `"Generated content flagged as duplicate"` in `logs/bot.log`.

### Test Error Handling and Backoff

Temporarily set an invalid `HF_TOKEN` value and run manually:

```bash
php public/cron.php
```

You should see an error logged in `logs/bot.log`, `next_retry_at` set in
`data/state.json`, and the script exit without Fatal Error and without publishing.
Until `POST_RETRY_AFTER_MINUTES` elapses, subsequent Cron runs will silently skip.

### Test Lock (prevent concurrent runs)

```bash
php public/cron.php &
php public/cron.php
```

The second run should immediately exit with a "already running" message.

---

## Security Best Practices

* No token is hardcoded in the code; everything is read from `.env` (or
  server-level env vars).
* Folders `data/`, `logs/`, `storage/`, `tools/` are completely blocked from
  direct browser access via `.htaccess` (`Require all denied`).
* `public/.htaccess` additionally blocks any file with extensions `.env`, `.json`,
  `.log`, `.lock`, `.md` as an extra safeguard in case these files mistakenly
  end up inside `public/`.
* Webhook is only accepted with a `secret_token` verified by Telegram
  (`X-Telegram-Bot-Api-Secret-Token` header).
* URL-based cron trigger is protected with a separate `CRON_SECRET` from the webhook.
* Administrative bot commands are restricted to `TELEGRAM_ADMIN_IDS` only.
* PHP errors are never displayed directly on screen (`display_errors=0`).
* JSON file writes are atomic (`temp file + rename`) and protected with `LOCK_EX`.
* If a JSON file corrupts, the system doesn't crash: the corrupted version is kept
  as `.bak` and replaced with a default structure.
* `DeepSeek` is explicitly forbidden from fabricating URLs/Sources; additionally,
  `ContentValidator` validates every URL with `filter_var(FILTER_VALIDATE_URL)` and
  also validates the `confidence` field.
* `SearchResultFilter` can even verify each source URL's liveness with an HTTP HEAD
  request before accepting it (`SEARCH_VERIFY_URLS=true`).

---

## Known Limitations

* `DuckDuckGoSearchService` is a free HTML scrape (not an official API) and is only
  intended as the last link in the fallback chain; if DuckDuckGo's HTML structure
  changes, it may return no results — this is fine, as the fallback chain and
  evergreen content will replace it.
* If you later want to migrate to a database, only `JsonStorage` and
  `HistoryRepository` need to be replaced.

---

## Installation

### Requirements

- PHP 8.2+ with extensions: `curl`, `mbstring`, `json`, `dom`
- Cron Job access
- Telegram Bot (from [@BotFather](https://t.me/BotFather))
- Hugging Face API Token
- (Optional) Search API keys (Tavily, Serper, Brave)

### Quick Start

1. Clone the repository:
   ```bash
   git clone https://github.com/Hossein-Mirzaei/telegram-content-bot.git
   cd telegram-content-bot
   ```

2. Copy environment file:
   ```bash
   cp .env.example .env
   ```

3. Edit `.env` with your settings

4. Upload to your hosting (see Deployment section)

5. Set up Cron Job:
   ```bash
   */15 * * * * /usr/bin/php /path/to/public/cron.php
   ```

## Usage

### Commands

- `/start` - Start bot and get welcome message
- `/help` - Show help
- `/status` - (Admin only) Show system status
- `/next` - (Admin only) Show next post time
- `/generate` - (Admin only) Generate a post immediately
- `/last` - (Admin only) Show last published posts
- `/stop` - (Admin only) Pause auto-publishing

### Configuration

See `.env.example` for all available configuration options.

## License

Proprietary - All rights reserved

## Author

[Hossein Mirzaei](https://t.me/Hoseiin_28) - Software Engineer

## Support

For issues and questions, please contact the author.
