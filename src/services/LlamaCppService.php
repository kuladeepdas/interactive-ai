<?php

namespace kdgraphics\interactiveai\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use kdgraphics\interactiveai\InteractiveAi;

class LlamaCppService extends Component
{
    public function getStatus(): array
    {
        $settings = InteractiveAi::$plugin->getSettings();
        $enabled = (bool)($settings->enableLlamaCpp ?? false);
        $binaryPath = trim((string)($settings->llamaCppBinaryPath ?? ''));
        $modelPath = trim((string)($settings->llamaCppModelPath ?? ''));

        $binaryReady = $binaryPath !== '' && is_file($binaryPath) && is_executable($binaryPath);
        $modelReady = $modelPath !== '' && is_file($modelPath);
        $ready = $enabled && $binaryReady && $modelReady;

        $message = match (true) {
            !$enabled => 'llama.cpp is disabled. Site Assist will use the built-in summarizer and local response engine.',
            !$binaryReady => 'llama.cpp is enabled, but the configured binary path is missing or not executable.',
            !$modelReady => 'llama.cpp is enabled, but the configured model path is missing.',
            default => 'llama.cpp is ready for summaries and answer refinement.',
        };

        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'binaryPath' => $binaryPath,
            'modelPath' => $modelPath,
            'message' => $message,
            'summaryEnabled' => $ready && (bool)($settings->useLlamaForSummaries ?? true),
            'answersEnabled' => $ready && (bool)($settings->useLlamaForAnswers ?? true),
        ];
    }

    public function summarize(string $text, string $title = '', int $sentences = 3): ?string
    {
        $status = $this->getStatus();
        if (!$status['summaryEnabled']) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $sentenceCount = max(2, min(4, $sentences));
        $prompt = 'Summarize the following website content for a visitor in ' . $sentenceCount . ' concise sentences. Be clear, accurate, and natural.';
        if ($title !== '') {
            $prompt .= ' Page title: ' . $title . '.';
        }
        $prompt .= "\n\nContent:\n" . StringHelper::truncate($text, 7000);
        $prompt .= "\n\nSummary:";

        return $this->runPrompt($prompt, 700);
    }

    public function refineAnswer(string $message, string $draft, array $pageContext = [], array $context = []): ?string
    {
        $status = $this->getStatus();
        if (!$status['answersEnabled']) {
            return null;
        }

        $draft = trim($draft);
        if ($draft === '') {
            return null;
        }

        $title = trim((string)($pageContext['title'] ?? ''));
        $headings = implode(', ', array_slice(array_values(array_filter((array)($pageContext['headings'] ?? []), 'is_string')), 0, 4));
        $assistantName = InteractiveAi::$plugin->getAssistantDisplayName();
        $siteName = Craft::$app->getSites()->getCurrentSite()->name;
        $emotionalTone = $this->inferEmotionalTone($message);

        $intentGuidance = $this->buildIntentGuidance($message, $draft);
        $conversationContext = $this->buildConversationContext((array)($context['conversation'] ?? []));
        $sectionContext = $this->buildSectionContext((array)($context['sections'] ?? []));
        $suggestionContext = $this->buildSuggestionContext((array)($context['suggestions'] ?? []), (array)($context['actions'] ?? []));
        $styleExamples = $this->buildStyleExamples();

        $prompt = "You are {$assistantName}, a warm, naturally conversational website host for {$siteName}.\n";
        $prompt .= "Your job is to turn the draft reply into the final visitor-facing reply without losing relevance.\n";
        $prompt .= "Stay specific. Do not replace a concrete answer with a generic greeting or generic help offer.\n";
        $prompt .= "Preserve the facts, intent, navigation guidance, section names, page names, content titles, counts, and actions already present in the draft.\n";
        $prompt .= "Do not invent facts, pages, offers, actions, or summaries.\n";
        $prompt .= "If the user asks about a section such as services, testimonials, news, jobs, events, or locations, keep that section central in the answer.\n";
        $prompt .= "If the user asks for a summary, return a concise summary instead of redirecting to generic help.\n";
        $prompt .= "If the user says the previous answer was not helpful or not interesting, acknowledge that briefly and then improve the answer directly.\n";
        $prompt .= "Speak naturally: friendly, calm, clear, lightly human, and never robotic or technical.\n";
        $prompt .= "Use a touch of natural feeling where it fits, but keep it subtle and believable.\n";
        $prompt .= "Do not repeat prompt labels or meta commentary. Output only the final visitor-facing reply.\n\n";
        $prompt .= "Desired emotional tone: {$emotionalTone}\n";
        if ($intentGuidance !== '') {
            $prompt .= "Intent guidance: {$intentGuidance}\n";
        }
        if ($title !== '') {
            $prompt .= "Current page: {$title}\n";
        }
        if ($headings !== '') {
            $prompt .= "Visible topics: {$headings}\n";
        }
        if ($sectionContext !== '') {
            $prompt .= "Known site sections: {$sectionContext}\n";
        }
        if ($conversationContext !== '') {
            $prompt .= "Recent conversation: {$conversationContext}\n";
        }
        if ($suggestionContext !== '') {
            $prompt .= "Available follow-up options: {$suggestionContext}\n";
        }
        if ($styleExamples !== '') {
            $prompt .= "\nExamples of the style to follow:\n{$styleExamples}\n";
        }
        $prompt .= "User message: {$message}\n";
        $prompt .= "Draft reply: {$draft}\n";
        $prompt .= "\nFinal reply:";

        return $this->runPrompt($prompt, 500, 0.55, $draft);
    }

    private function buildIntentGuidance(string $message, string $draft): string
    {
        $normalized = mb_strtolower(trim($message));
        $draftNormalized = mb_strtolower(trim($draft));

        if (preg_match('/\b(summary of|summarize|overview of)\b/i', $normalized)) {
            return 'Answer with a direct summary. Keep it concise and informative.';
        }

        if (preg_match('/\b(testimonials|reviews|services|news|jobs|events|locations)\b/i', $normalized)) {
            return 'Answer directly about the named site content area. Do not switch to generic assistance language.';
        }

        if (preg_match('/\b(interesting|surprise me)\b/i', $normalized)) {
            return 'Say one site-relevant interesting thing, not a generic greeting.';
        }

        if (preg_match('/\b(not interesting|not helpful|that was not)\b/i', $normalized)) {
            return 'Briefly acknowledge the feedback, then give a stronger direct answer.';
        }

        if (preg_match('/\b(hi|hello|hey)\b/i', $normalized) && $draftNormalized !== '') {
            return 'Keep the warmth brief and avoid turning the answer into generic welcome text.';
        }

        return 'Keep the draft relevant and specific to the user question.';
    }

    private function buildConversationContext(array $conversation): string
    {
        if ($conversation === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($conversation, -4) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = trim((string)($item['role'] ?? ''));
            $content = trim((string)($item['content'] ?? ''));
            if ($role === '' || $content === '') {
                continue;
            }

            $lines[] = $role . ': ' . StringHelper::truncate($content, 140);
        }

        return implode(' | ', $lines);
    }

    private function buildSectionContext(array $sections): string
    {
        if ($sections === []) {
            return '';
        }

        $labels = [];
        foreach (array_slice($sections, 0, 8) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $name = trim((string)($section['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $count = (int)($section['count'] ?? 0);
            $labels[] = $count > 0 ? "{$name} ({$count})" : $name;
        }

        return implode(', ', $labels);
    }

    private function buildSuggestionContext(array $suggestions, array $actions): string
    {
        $labels = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $label = trim((string)($action['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        foreach ($suggestions as $suggestion) {
            $label = trim((string)$suggestion);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $labels = array_values(array_unique($labels));

        return implode(', ', array_slice($labels, 0, 6));
    }

    private function buildStyleExamples(): string
    {
        $examples = [
            [
                'user' => 'testimonials',
                'bad' => 'Hello! How can I assist you today?',
                'good' => 'The testimonials section is where the site shows client feedback. If you want, I can help you open it or give you a quick summary of what people are saying.',
            ],
            [
                'user' => 'summary of client testimonials',
                'bad' => 'Hello! Is there anything specific you would like to discuss?',
                'good' => 'The testimonials section gives a feel for how clients describe their experience. If you want, I can take you there next or pull out a few specific highlights.',
            ],
            [
                'user' => 'say something interesting',
                'bad' => 'How can I help you today?',
                'good' => 'One interesting thing here is that the site has a few clear content areas to explore. If you want, I can point you to the most useful one to start with.',
            ],
            [
                'user' => 'that was not interesting',
                'bad' => 'Is there anything specific you would like to discuss?',
                'good' => 'Fair enough. Let me do better. A more useful place to look here is the section that seems most active on the site, and I can take you there if you want.',
            ],
        ];

        $lines = [];
        foreach ($examples as $example) {
            $lines[] = 'User: ' . $example['user'];
            $lines[] = 'Avoid: ' . $example['bad'];
            $lines[] = 'Prefer: ' . $example['good'];
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function inferEmotionalTone(string $message): string
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return 'warm and welcoming';
        }

        if (preg_match('/\b(thanks|thank you|cheers)\b/i', $normalized)) {
            return 'warm and appreciative';
        }

        if (preg_match('/\b(hi|hello|hey)\b/i', $normalized)) {
            return 'welcoming and easygoing';
        }

        if (preg_match('/\b(joke|funny|laugh)\b/i', $normalized)) {
            return 'lightly playful';
        }

        if (preg_match('/\b(help|confused|stuck|not working|problem|issue|error)\b/i', $normalized)) {
            return 'calm and reassuring';
        }

        if (preg_match('/\b(wow|great|awesome|amazing|love)\b/i', $normalized)) {
            return 'bright and encouraging';
        }

        if (preg_match('/\b(who are you|what is your name|are you learning|can you)\b/i', $normalized)) {
            return 'friendly and thoughtful';
        }

        return 'warm and conversational';
    }

    private function runPrompt(string $prompt, int $maxTokens = 600, float $temperature = 0.4, ?string $draft = null): ?string
    {
        $settings = InteractiveAi::$plugin->getSettings();
        $binaryPath = trim((string)($settings->llamaCppBinaryPath ?? ''));
        $modelPath = trim((string)($settings->llamaCppModelPath ?? ''));
        $contextSize = max(1024, (int)($settings->llamaCppContextSize ?? 4096));

        if ($binaryPath === '' || $modelPath === '') {
            return null;
        }

        $command = 'printf ' . escapeshellarg("/exit\n")
            . ' | timeout 25 '
            . escapeshellarg($binaryPath)
            . ' -m ' . escapeshellarg($modelPath)
            . ' -n ' . (int)$maxTokens
            . ' -c ' . (int)$contextSize
            . ' --temp ' . escapeshellarg((string)$temperature)
            . ' --simple-io'
            . ' --no-display-prompt'
            . ' --no-perf'
            . ' --log-disable'
            . ' -p ' . escapeshellarg($prompt);

        $result = $this->runCommand($command, 35);
        if (($result['exitCode'] ?? 1) !== 0) {
            Craft::warning('llama.cpp invocation failed: ' . trim((string)($result['stderr'] ?? '')), __METHOD__);
            return null;
        }

        $output = trim((string)($result['stdout'] ?? ''));
        if ($output === '') {
            return null;
        }

        $output = $this->cleanLlamaOutput($output);
        $output = $this->stripPromptEcho($output, $prompt);
        $output = $this->extractVisitorFacingReply($output);
        $output = preg_replace('/^\s*(assistant:|response:|better reply:)\s*/i', '', $output) ?? $output;
        $output = preg_replace('/\s+/', ' ', $output) ?? $output;
        $output = trim($output);

        if ($this->looksLikePromptLeakage($output)) {
            Craft::warning('Discarding llama.cpp output because it still contains prompt leakage.', __METHOD__);
            return null;
        }

        if ($draft !== null && $this->looksTooGenericComparedToDraft($output, $draft)) {
            Craft::warning('Discarding llama.cpp output because it is more generic than the draft.', __METHOD__);
            return null;
        }

        return trim(StringHelper::truncate($output, 4000));
    }

    private function looksTooGenericComparedToDraft(string $output, string $draft): bool
    {
        $outputNormalized = mb_strtolower(trim($output));
        $draftNormalized = mb_strtolower(trim($draft));

        if ($outputNormalized === '' || $draftNormalized === '') {
            return false;
        }

        $genericPatterns = [
            '/^hello[!.!, ]/i',
            '/^hi[!.!, ]/i',
            '/^how can i assist you today\??$/i',
            '/^is there anything specific/i',
            '/^i am here to help/i',
        ];

        $outputLooksGeneric = false;
        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $outputNormalized)) {
                $outputLooksGeneric = true;
                break;
            }
        }

        if (!$outputLooksGeneric) {
            return false;
        }

        $draftHasSpecificSiteTerms = (bool)preg_match('/\b(testimonials?|services?|news|jobs|events|locations|reviews|summary|section|page)\b/i', $draftNormalized);

        return $draftHasSpecificSiteTerms;
    }

    private function cleanLlamaOutput(string $output): string
    {
        $output = str_replace("\r", '', $output);
        $output = preg_replace('/Loading model\.\.\.\s*/i', '', $output) ?? $output;
        $output = preg_replace('/available commands:.*?(?=\n>\s)/si', '', $output) ?? $output;
        $output = preg_replace('/^\s*> ?/m', '', $output) ?? $output;
        $output = preg_replace('/\[ Prompt:.*?\]/si', '', $output) ?? $output;
        $output = preg_replace('/Exiting\.\.\.\s*$/i', '', $output) ?? $output;
        $output = preg_replace('/^\s*(build|model|modalities)\s*:.*$/mi', '', $output) ?? $output;
        $output = preg_replace('/^[\x{2580}-\x{259F}\x{2500}-\x{257F}\x{2800}-\x{28FF}\s]+$/mu', '', $output) ?? $output;
        $output = preg_replace('/^\s*\/(exit|regen|clear|read).*$/mi', '', $output) ?? $output;
        $output = preg_replace('/\n{2,}/', "\n\n", trim($output)) ?? trim($output);

        return trim($output);
    }

    private function stripPromptEcho(string $output, string $prompt): string
    {
        $normalize = static fn(string $value): string => trim((string)(preg_replace('/\s+/', ' ', $value) ?? $value));

        $normalizedOutput = $normalize($output);
        $normalizedPrompt = $normalize($prompt);

        if ($normalizedPrompt !== '' && str_starts_with($normalizedOutput, $normalizedPrompt)) {
            return trim(substr($normalizedOutput, strlen($normalizedPrompt)));
        }

        foreach (['Summary:', 'Final reply:', 'Better reply:'] as $marker) {
            $position = strripos($output, $marker);
            if ($position !== false) {
                return trim(substr($output, $position + strlen($marker)));
            }
        }

        if (preg_match('/\bSummarize the following website content\b/i', $output)) {
            $summaryPosition = strripos($output, 'Content:');
            if ($summaryPosition !== false) {
                $afterContent = trim(substr($output, $summaryPosition + strlen('Content:')));
                $sentences = preg_split('/(?<=[.!?])\s+/', $afterContent) ?: [];
                if (count($sentences) > 1) {
                    return trim(implode(' ', array_slice($sentences, -2)));
                }
            }
        }

        return trim($output);
    }

    private function looksLikePromptLeakage(string $output): bool
    {
        if ($output === '') {
            return true;
        }

        return (bool)preg_match(
            '/\b('
            . 'rewrite the following website assistant reply'
            . '|summarize the following website content'
            . '|user message:'
            . '|draft reply:'
            . '|content:'
            . '|page title:'
            . '|visible topics:'
            . '|final reply:'
            . '|better reply:'
            . ')\b/i',
            $output
        );
    }

    private function extractVisitorFacingReply(string $output): string
    {
        $output = trim($output);
        if ($output === '') {
            return '';
        }

        $lines = preg_split('/\n+/', $output) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));

        if ($lines === []) {
            return '';
        }

        $cleanLines = array_values(array_filter($lines, function(string $line): bool {
            return !$this->looksLikePromptLeakage($line);
        }));

        if ($cleanLines !== []) {
            return trim(end($cleanLines));
        }

        return $output;
    }

    private function runCommand(string $command, int $timeout = 60): array
    {
        if (!function_exists('proc_open')) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'proc_open is unavailable'];
        }

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(['/bin/sh', '-lc', $command], $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Unable to start process'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();

        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ((time() - $start) >= $timeout) {
                proc_terminate($process);
                break;
            }

            usleep(150000);
        } while (true);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }
}
