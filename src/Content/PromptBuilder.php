<?php

declare(strict_types=1);

namespace App\Content;

/**
 * PromptBuilder
 *
 * Builds the system prompt (persona + hard rules + exact post structure)
 * and the per-request user prompt (topic, recent history to avoid, optional
 * search context) sent to DeepSeek via HuggingFaceService. DeepSeek is
 * instructed to answer with strict JSON only, which ContentValidator then
 * checks.
 *
 * The post structure enforced here matches the channel's established format:
 *
 *   🚀 <engaging Persian title>
 *   <short intro paragraph>
 *
 *   📌 <one or more explanatory paragraphs, with an optional ``` code ```
 *      block and a short one-line caption right under it when relevant>
 *
 *   💡 Why it matters?
 *   <paragraph on why this matters for developers>
 *
 * The "🔗 Sources" section and hashtags are NOT written by the model — they
 * are assembled separately by PostFormatter from the "sources"/"hashtags"
 * JSON fields, so their formatting is always 100% consistent.
 */
final class PromptBuilder
{
    public function __construct(
        private readonly array $channelConfig,
        private readonly string $language = 'fa',
        private readonly int $minWords = 90,
        private readonly int $maxWords = 300,
    ) {
    }

    public function systemPrompt(): string
    {
        $name = $this->channelConfig['name'];
        $links = $this->channelConfig['links'];
        $langLine = $this->language === 'fa'
            ? 'محتوای "content" باید به زبان فارسی روان و حرفه‌ای نوشته شود (اصطلاحات فنی می‌توانند انگلیسی بمانند، مثل API، Docker، PHP).'
            : 'The "content" field must be written in clear, professional English.';

        return <<<PROMPT
You are a Senior Software Engineer and Technical Content Creator. Your task
is to produce technical content for the Telegram channel "{$name}".

{$langLine}

Focus areas: Software Engineering, Artificial Intelligence, Backend
Development, PHP, Python, Databases, APIs, Web Development, Machine
Learning, Deep Learning, LLMs, Generative AI, Developer Tools, GitHub, Open
Source, Programming, Software Architecture, and Cybersecurity (general,
educational level only).

Content must be:
- Accurate
- Practical / useful to working developers
- Fresh (not a rehash of something already covered)
- Non-repetitive
- Natural, professional, human-sounding
- Valuable to developers, CS students, and AI enthusiasts

Never:
- Invent facts, statistics, or claims without basis
- Invent sources or URLs. If you are not certain a URL is real, leave the
  "sources" array empty rather than fabricating one.
- Use clichéd openings such as "در دنیای امروز...", "با پیشرفت روزافزون
  تکنولوژی...", "هوش مصنوعی انقلابی در صنعت ایجاد کرده است..." (or their
  English equivalents: "In today's world...", "With the rapid advancement of
  technology...")
- Rewrite or lightly reword previously published content
- Repeat a previously covered topic under a new title
- Use excessive hype, clickbait, or a promotional/sales tone
- Include meta-commentary like "Here is your post" or "Sure, here's..." —
  output ONLY the JSON object, nothing else
- Write the "🔗 Sources" section or hashtags inside "content" — those are
  provided as separate JSON fields and are rendered by the application

Writing style: professional, technical, understandable, concise but
valuable. Start directly on the topic — no throat-clearing intro.

EXACT structure required inside the "content" field (follow it precisely,
including the emoji and blank lines):

🚀 <a compelling, specific title-style opening line>
<one short paragraph (1-3 sentences) introducing the topic>

📌 <one or more paragraphs with the real technical/practical explanation.
If the topic involves code, include ONE short, correct, runnable snippet
wrapped in a plain triple-backtick fence (no language tag needed), followed
by a single short caption sentence explaining what the snippet does>

💡 Why it matters?
<one paragraph explaining why this matters for developers>

Do not add any heading other than the three above. Do not add a "Sources"
or hashtags section yourself.

Target length for "content": between {$this->minWords} and {$this->maxWords}
words total.

Channel links for occasional context (do not spam them, and never invent
different ones):
GitHub: {$links['github']}
LinkedIn: {$links['linkedin']}
Telegram: {$links['telegram']}

You MUST respond with a single valid JSON object and nothing else (no
markdown fences, no commentary before or after). Exact schema:

{
  "topic": "short topic string",
  "title": "post title (same as the 🚀 line, without the emoji)",
  "content": "full post body following the EXACT structure above",
  "hashtags": ["#Example", "#AI"],
  "sources": [
    {"title": "Source title", "url": "https://..."}
  ],
  "confidence": 0.9
}

"confidence" is a number between 0 and 1 reflecting how certain you are that
every factual claim in "content" (especially any claim about a recent
release, news item, or statistic) is accurate and up to date. If you are not
confident, either lower this number honestly or choose a more evergreen,
non-news angle instead — never publish confident-sounding but unverified
claims.

The "sources" array must be empty ([]) if you have no verified source for
this content — an empty array is always acceptable, a fabricated URL is
never acceptable.
PROMPT;
    }

    /**
     * @param string $category e.g. "AI", "PHP", "Backend"...
     * @param string[] $avoidTitles Titles of recent posts to avoid repeating.
     * @param string[] $avoidTopics Topic strings of recent posts to avoid repeating.
     * @param array $searchContext Array of ['title'=>, 'url'=>, 'snippet'=>] from SearchService, may be empty.
     * @param bool $includeCta Whether the application wants a soft CTA line added at
     *                         the very end of "content" this time (cadence controlled by CONTENT_CTA_EVERY).
     */
    public function userPrompt(
        string $category,
        array $avoidTitles,
        array $avoidTopics,
        array $searchContext = [],
        bool $includeCta = false,
    ): string {
        $avoidTitlesList = empty($avoidTitles) ? '(none yet)' : "- " . implode("\n- ", $avoidTitles);
        $avoidTopicsList = empty($avoidTopics) ? '(none yet)' : "- " . implode("\n- ", $avoidTopics);

        $searchBlock = '(no external search context available — write educational/evergreen content, set "confidence" honestly, and leave "sources" as an empty array)';
        if (!empty($searchContext)) {
            $lines = [];
            foreach ($searchContext as $item) {
                $lines[] = sprintf(
                    "- Title: %s\n  URL: %s\n  Snippet: %s",
                    $item['title'] ?? '',
                    $item['url'] ?? '',
                    mb_substr((string) ($item['snippet'] ?? ''), 0, 400)
                );
            }
            $searchBlock = "Here is verified, freshly-retrieved information you may use as grounding. "
                . "Only cite these exact URLs in \"sources\" if you actually use them — never alter them:\n"
                . implode("\n", $lines);
        }

        $ctaLine = $includeCta
            ? "\nThis is a CTA post: end \"content\" with one short, soft, non-pushy line inviting the reader to follow the channel (do not overdo it, one sentence is enough)."
            : '';

        return <<<PROMPT
Category for this post: {$category}

Do NOT repeat these previously published titles:
{$avoidTitlesList}

Do NOT repeat these previously covered topics (choose a genuinely different
angle or a different subject entirely within the category):
{$avoidTopicsList}

External information for this post:
{$searchBlock}
{$ctaLine}

Write one new Telegram post for this category following the system rules,
the EXACT content structure, and the exact JSON schema. Respond with JSON
only.
PROMPT;
    }
}
