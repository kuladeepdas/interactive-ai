<?php

namespace kdgraphics\interactiveai\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\StringHelper;

class NativeSummarizer extends Component
{
    public function summarizeText(string $text, string $title = '', int $sentences = 3): ?string
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return null;
        }

        $sentenceLimit = max(2, min(4, $sentences));
        $parts = preg_split('/(?<=[\.\!\?])\s+/u', $text) ?: [];
        $sentencesPool = [];

        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '' || $this->isLowSignalSentence($candidate)) {
                continue;
            }

            $sentencesPool[] = $candidate;
        }

        if ($sentencesPool === []) {
            return null;
        }

        $keywordWeights = $this->keywordWeights($text, $title);
        $scored = [];

        foreach ($sentencesPool as $index => $sentence) {
            $score = $this->scoreSentence($sentence, $keywordWeights, $index, $title);
            $scored[] = [
                'index' => $index,
                'sentence' => $sentence,
                'score' => $score,
            ];
        }

        usort($scored, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']));
        $selected = array_slice($scored, 0, min($sentenceLimit, count($scored)));
        usort($selected, static fn(array $a, array $b): int => ($a['index'] <=> $b['index']));

        $summary = implode(' ', array_map(static fn(array $item): string => $item['sentence'], $selected));
        $summary = trim(preg_replace('/\s+/', ' ', $summary) ?? $summary);

        return $summary !== '' ? StringHelper::truncate($summary, 800) : null;
    }

    public function summarizeEntry(Entry $entry, string $fallback = ''): ?string
    {
        $cacheKey = 'site-assist-entry-summary:' . $entry->id . ':' . $entry->dateUpdated?->getTimestamp();
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);
        if (is_string($cached) && trim($cached) !== '') {
            return $cached;
        }

        $chunks = [];
        $chunks[] = $entry->title;
        $chunks[] = $fallback;

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $entry->getFieldValue($field->handle);
            if (is_scalar($value)) {
                $chunks[] = (string)$value;
            }
        }

        $summary = $this->summarizeText(implode(' ', $chunks), $entry->title);
        if ($summary !== null) {
            $cache->set($cacheKey, $summary, 86400);
        }

        return $summary;
    }

    public function summarizePageContext(array $pageContext): ?string
    {
        $title = trim((string)($pageContext['title'] ?? ''));
        $text = implode(' ', array_filter([
            $title,
            (string)($pageContext['description'] ?? ''),
            implode(' ', array_filter((array)($pageContext['headings'] ?? []), 'is_string')),
            (string)($pageContext['contentExcerpt'] ?? ''),
        ]));

        return $this->summarizeText($text, $title);
    }

    public function getStatus(): array
    {
        return [
            'ready' => true,
            'message' => 'Native summarizer is ready inside the plugin.',
            'mode' => 'native',
        ];
    }

    private function normalizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return trim($text);
    }

    private function isLowSignalSentence(string $sentence): bool
    {
        $lower = mb_strtolower($sentence);

        if (mb_strlen($sentence) < 24) {
            return true;
        }

        if (preg_match('/\blorem ipsum\b/i', $sentence)) {
            return true;
        }

        if (preg_match('/#[0-9a-f]{3,8}\b/i', $sentence)) {
            return true;
        }

        if (preg_match('/\b(?:craft|yii)\\\\/i', $sentence) || str_contains($sentence, 'ElementQuery')) {
            return true;
        }

        foreach ([
            'it is a long established fact that a reader will be distracted',
            'the point of using lorem ipsum',
        ] as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function keywordWeights(string $text, string $title = ''): array
    {
        $combined = mb_strtolower(trim($title . ' ' . $text));
        $combined = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $combined) ?? $combined;
        $tokens = preg_split('/\s+/', $combined) ?: [];
        $weights = [];
        $stopWords = $this->stopWords();

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3 || isset($stopWords[$token])) {
                continue;
            }

            $weights[$token] = ($weights[$token] ?? 0) + 1;
        }

        arsort($weights);

        return array_slice($weights, 0, 40, true);
    }

    private function scoreSentence(string $sentence, array $keywordWeights, int $index, string $title = ''): float
    {
        $normalized = mb_strtolower($sentence);
        $score = 0.0;

        foreach ($keywordWeights as $keyword => $weight) {
            if (str_contains($normalized, $keyword)) {
                $score += min(6, $weight);
            }
        }

        if ($index === 0) {
            $score += 4.0;
        } elseif ($index === 1) {
            $score += 2.0;
        }

        if ($title !== '' && str_contains($normalized, mb_strtolower($title))) {
            $score += 3.0;
        }

        $length = mb_strlen($sentence);
        if ($length >= 60 && $length <= 260) {
            $score += 2.0;
        } elseif ($length > 320) {
            $score -= 1.0;
        }

        return $score;
    }

    private function stopWords(): array
    {
        return array_fill_keys([
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'have', 'your', 'about', 'into', 'here',
            'there', 'they', 'them', 'their', 'will', 'would', 'could', 'should', 'what', 'when', 'where',
            'which', 'while', 'been', 'being', 'does', 'did', 'just', 'also', 'than', 'then', 'were', 'was',
            'are', 'our', 'you', 'can', 'not', 'but', 'all', 'any', 'how', 'has', 'had', 'its', 'who',
        ], true);
    }
}
