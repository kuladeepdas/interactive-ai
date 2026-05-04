<?php

namespace kdgraphics\interactiveai\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\StringHelper;
use DateTimeImmutable;
use DateTimeZone;
use kdgraphics\interactiveai\InteractiveAi;
use yii\base\InvalidConfigException;

class Assistant extends Component
{
    private function getAssistantDisplayName(): string
    {
        return InteractiveAi::$plugin->getAssistantDisplayName();
    }

    public function invalidateKnowledgeIndexes(): void
    {
        $pattern = Craft::getAlias('@storage/interactive-ai/knowledge-index-*.json');
        $paths = glob($pattern) ?: [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function respond(array $payload): array
    {
        $message = trim((string)($payload['message'] ?? ''));
        $conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : [];
        $pageContext = is_array($payload['pageContext'] ?? null) ? $payload['pageContext'] : [];
        $profile = is_array($payload['profile'] ?? null) ? $payload['profile'] : [];
        $memory = is_array($payload['memory'] ?? null) ? $payload['memory'] : [];
        $message = $this->expandPromptDrivenMessage($message, $memory);
        $siteId = (int)(Craft::$app->getSites()->getCurrentSite()->id ?? 0);
        $learningState = $this->loadLearningState();
        $knowledgeIndex = $this->getKnowledgeIndex($siteId);
        $knowledgeIndex = $this->learnFromPageContext($knowledgeIndex, $pageContext, $siteId);
        $internetKnowledge = $this->loadInternetKnowledge();

        if ($message === '') {
            $assistantName = $this->getAssistantDisplayName();
            return [
                'message' => "Hi, I am {$assistantName}. I am here to help you explore this website, find the right page, and make forms feel easy.",
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
            ];
        }

        $learningState = $this->rememberInteraction($learningState, $message, $pageContext, $memory);
        $internetKnowledge = $this->rememberInternetTopic($internetKnowledge, $message);
        $this->saveLearningState($learningState);
        $this->saveInternetKnowledge($internetKnowledge);

        $response = $this->buildLocalResponse($message, $pageContext, $profile, $memory, $siteId, $conversation, $knowledgeIndex, $learningState, $internetKnowledge);

        if ($this->shouldUseLlamaForAllResponses($message)) {
            $llamaReply = InteractiveAi::$plugin->getLlamaCpp()->refineAnswer(
                $message,
                (string)($response['message'] ?? ''),
                $pageContext,
                [
                    'conversation' => $conversation,
                    'sections' => (array)($knowledgeIndex['sections'] ?? []),
                    'suggestions' => (array)($response['suggestions'] ?? []),
                    'actions' => (array)($response['actions'] ?? []),
                ]
            );
            if (is_string($llamaReply) && trim($llamaReply) !== '') {
                $response['message'] = trim($llamaReply);
            }
        }

        $modelMessage = $this->fetchRemoteResponse(
            $message,
            $pageContext,
            $conversation,
            $profile,
            $siteId,
            $learningState,
            $knowledgeIndex,
            $response,
            $response['confidence'] ?? 0.0
        );

        if ($modelMessage) {
            $response['message'] = $modelMessage;

            if (empty($response['suggestions'])) {
                $response['suggestions'] = $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState);
            }
        }

        $response['message'] = $this->formatResponseParagraphs((string)($response['message'] ?? ''));
        unset($response['confidence']);

        return $response;
    }

    private function shouldUseLlamaForAllResponses(string $message): bool
    {
        $settings = InteractiveAi::$plugin->getSettings();

        if (
            !(bool)($settings->useLlamaForAllResponses ?? false)
            || !(bool)($settings->enableLlamaCpp ?? false)
        ) {
            return false;
        }

        return !$this->shouldBypassLlamaForMessage($message);
    }

    private function shouldBypassLlamaForMessage(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return true;
        }

        if (mb_strlen($normalized) <= 24 && $this->isPureGreeting($normalized)) {
            return true;
        }

        return $this->isJokeIntent($normalized)
            || $this->isThanksIntent($normalized)
            || $this->isHowAreYouIntent($normalized)
            || $this->isStuckIntent($normalized)
            || $this->isWhoAreYouIntent($normalized)
            || $this->isFormIntent($normalized)
            || preg_match('/\b(empty|blank|missing)\b/i', $normalized) === 1
            || preg_match('/^\s*my\s+.{1,80}\s+is\s+.+$/ui', $normalized) === 1
            || preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $normalized) === 1
            || $this->isNameQuestion($normalized)
            || $this->isLearningQuestion($normalized)
            || $this->isCapabilityIntent($normalized)
            || $this->isInterestingIntent($normalized)
            || $this->isSectionSummaryIntent($normalized)
            || preg_match('/^\s*[\p{L}\p{N}\- ]{2,40}\s*$/u', $normalized);
    }

    private function formatResponseParagraphs(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return $message;
        }

        if (str_contains($message, "\n\n")) {
            return $message;
        }

        $jokeParagraphs = $this->formatJokeStyleParagraphs($message);
        if ($jokeParagraphs !== null) {
            return $jokeParagraphs;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $message) ?: [];
        $sentences = array_values(array_filter(array_map('trim', $sentences), static fn(string $sentence): bool => $sentence !== ''));

        $specialParagraphs = $this->formatSpecialConversationParagraphs($sentences);
        if ($specialParagraphs !== null) {
            return $specialParagraphs;
        }

        if (count($sentences) < 4 || mb_strlen($message) < 220) {
            return $message;
        }

        $paragraphs = [];
        $current = [];

        foreach ($sentences as $index => $sentence) {
            $current[] = $sentence;
            $normalized = mb_strtolower($sentence);
            $isLast = $index === count($sentences) - 1;
            $shouldBreak = false;

            if (
                count($current) >= 2
                && preg_match('/^(if you|if you would like|if you want|tell me|let me|we can|next,|from here|that said|also,|and if|or if|if that helps)/i', $normalized)
            ) {
                $shouldBreak = true;
            }

            if (!$shouldBreak && count($current) >= 3) {
                $shouldBreak = true;
            }

            if ($isLast && $current !== []) {
                $shouldBreak = true;
            }

            if ($shouldBreak) {
                $paragraphs[] = implode(' ', $current);
                $current = [];
            }
        }

        if ($current !== []) {
            $paragraphs[] = implode(' ', $current);
        }

        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn(string $paragraph): bool => $paragraph !== ''));

        if (count($paragraphs) < 2) {
            return $message;
        }

        return implode("\n\n", array_slice($paragraphs, 0, 3));
    }

    private function formatJokeStyleParagraphs(string $message): ?string
    {
        if (!preg_match('/^(?<opener>.+?[.!?])\s+(?<joke>(?:\*[^*]+\*|_[^_]+_))\s+(?<tail>(?:If you|If you would like|If you want).+)$/us', $message, $matches)) {
            return null;
        }

        $opener = trim((string)($matches['opener'] ?? ''));
        $joke = trim((string)($matches['joke'] ?? ''));
        $tail = trim((string)($matches['tail'] ?? ''));

        if ($opener === '' || $joke === '' || $tail === '') {
            return null;
        }

        return implode("\n\n", [$opener, $joke, $tail]);
    }

    private function formatSpecialConversationParagraphs(array $sentences): ?string
    {
        if (count($sentences) < 3 || count($sentences) > 5) {
            return null;
        }

        $first = trim((string)($sentences[0] ?? ''));
        $firstPlain = trim($this->stripInlineEmphasis($first));

        if (
            $first === ''
            || $firstPlain === ''
            || mb_strlen($firstPlain) > 40
        ) {
            return null;
        }

        $transitionIndex = null;
        $hasQuestionBeforeTransition = false;

        foreach ($sentences as $index => $sentence) {
            $plainSentence = trim($this->stripInlineEmphasis(trim($sentence)));

            if ($index >= 1 && str_ends_with($plainSentence, '?')) {
                $hasQuestionBeforeTransition = true;
            }

            if ($index < 2) {
                continue;
            }

            if (preg_match('/^(if you|if you would like|if you want|tell me|let me|we can|next,|from here|that said|also,|and if|or if|if that helps)/i', $plainSentence)) {
                $transitionIndex = $index;
                break;
            }
        }

        if ($transitionIndex === null || !$hasQuestionBeforeTransition) {
            return null;
        }

        $paragraphs = [
            $first,
            implode(' ', array_slice($sentences, 1, $transitionIndex - 1)),
            implode(' ', array_slice($sentences, $transitionIndex)),
        ];

        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn(string $paragraph): bool => $paragraph !== ''));

        return count($paragraphs) >= 2 ? implode("\n\n", $paragraphs) : null;
    }

    private function stripInlineEmphasis(string $text): string
    {
        return trim(str_replace(['*', '_'], '', $text));
    }

    private function expandPromptDrivenMessage(string $message, array $memory): string
    {
        $starter = trim((string)($memory['pendingPromptStarter'] ?? ''));
        if ($starter === '' || $message === '') {
            return $message;
        }

        $normalizedStarter = $this->normalizeUiOption($starter);
        $normalizedMessage = $this->normalizeUiOption($message);

        if ($normalizedStarter === '' || str_starts_with($normalizedMessage, $normalizedStarter)) {
            return $message;
        }

        return trim($starter . ' ' . $message);
    }

    private function buildLocalResponse(
        string $message,
        array $pageContext,
        array $profile,
        array $memory,
        int $siteId,
        array $conversation,
        array $knowledgeIndex,
        array $learningState,
        array $internetKnowledge
    ): array {
        $normalized = mb_strtolower($message);
        $forms = is_array($pageContext['forms'] ?? null) ? $pageContext['forms'] : [];
        $headings = array_values(array_filter(array_map('trim', (array)($pageContext['headings'] ?? []))));
        $excerpt = trim((string)($pageContext['contentExcerpt'] ?? ''));

        $contentCountResponse = $this->buildContentCountResponse($normalized, $siteId, $knowledgeIndex);
        if ($contentCountResponse !== null) {
            return $contentCountResponse;
        }

        $profileResetResponse = $this->buildProfileResetResponse($message, $normalized, $pageContext, $knowledgeIndex, $learningState);
        if ($profileResetResponse !== null) {
            return $profileResetResponse;
        }

        $visitorIntent = $this->inferVisitorIntentProfile($message, $pageContext, $learningState, $memory);
        $selfResponse = $this->buildSelfResponse($message, $normalized, $pageContext, $knowledgeIndex, $learningState, $profile, $memory);
        if ($selfResponse !== null) {
            return $selfResponse;
        }

        $recommendationResponse = $this->buildRecommendationResponse($message, $normalized, $siteId, $pageContext, $knowledgeIndex, $learningState, $profile, $visitorIntent);
        if ($recommendationResponse !== null) {
            return $recommendationResponse;
        }

        $siteDiscoveryResponse = $this->buildSiteDiscoveryResponse($normalized, $siteId, $knowledgeIndex);
        if ($siteDiscoveryResponse !== null) {
            return $siteDiscoveryResponse;
        }

        $interestingResponse = $this->buildInterestingResponse($message, $siteId, $pageContext, $knowledgeIndex, $learningState, $profile);
        if ($interestingResponse !== null) {
            return $interestingResponse;
        }

        $sectionSummaryResponse = $this->buildSectionSummaryResponse($message, $siteId, $knowledgeIndex, $pageContext, $learningState, $profile);
        if ($sectionSummaryResponse !== null) {
            return $sectionSummaryResponse;
        }

        $sectionOverviewResponse = $this->buildSectionOverviewResponse($message, $siteId, $knowledgeIndex, $pageContext, $learningState, $profile);
        if ($sectionOverviewResponse !== null) {
            return $sectionOverviewResponse;
        }

        $sectionPromptResponse = $this->buildSectionPromptResponse($message, $siteId, $knowledgeIndex, $pageContext, $learningState, $profile);
        if ($sectionPromptResponse !== null) {
            return $sectionPromptResponse;
        }

        $sectionNavigationResponse = $this->buildSectionNavigationResponse($normalized, $siteId, $knowledgeIndex);
        if ($sectionNavigationResponse !== null) {
            return $sectionNavigationResponse;
        }

        $learnedNavigationResponse = $this->buildLearnedNavigationResponse($message, $normalized, $knowledgeIndex);
        if ($learnedNavigationResponse !== null) {
            return $learnedNavigationResponse;
        }

        if ($this->isPureGreeting($normalized)) {
            return [
                'message' => 'Hi, it is lovely to chat with you. I can help you explore the website, explain what you are looking at, or just keep things light while we figure out where to go next.',
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.95,
            ];
        }

        if ($this->isCurrentPageQuestion($normalized) && ($excerpt !== '' || $headings !== [])) {
            $summary = $this->summarizePageContext($pageContext);

            return [
                'message' => $summary,
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Show me somewhere related',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.88,
            ];
        }

        $profilePromptResponse = $this->buildProfilePromptResponse($normalized, $pageContext, $profile, $knowledgeIndex, $learningState);
        if ($profilePromptResponse !== null) {
            return $profilePromptResponse;
        }

        $formCorrectionResponse = $this->buildFormCorrectionResponse($message, $normalized, $pageContext, $profile, $knowledgeIndex, $learningState);
        if ($formCorrectionResponse !== null) {
            return $formCorrectionResponse;
        }

        $profileCaptureResponse = $this->buildProfileCaptureResponse($message, $normalized, $pageContext, $profile, $knowledgeIndex, $learningState);
        if ($profileCaptureResponse !== null) {
            return $profileCaptureResponse;
        }

        if ($this->isFormIntent($normalized) && $forms !== []) {
            $firstForm = $forms[0];
            $requiredFields = $this->getRequiredFields($firstForm);
            $missingFields = $this->getMissingProfileFields($requiredFields, $profile);
            $fieldList = $this->formatFieldList($firstForm);

            $messageText = 'I can help with the form here. ';
            $messageText .= $fieldList !== '' ? "It looks like I can guide you through {$fieldList}. " : '';

            if (!empty($memory['preferredContact'])) {
                $messageText .= 'I will keep in mind that you prefer to be contacted by ' . $memory['preferredContact'] . '. ';
            }

            if ($missingFields !== []) {
                $messageText .= 'If you would like, I can get started once I have your ' . $this->humanizeList($missingFields) . '.';
            } else {
                $messageText .= 'I already have enough to help you fill it in and take you straight to it.';
            }

            return [
                'message' => $messageText,
                'suggestions' => $missingFields !== []
                    ? $this->buildFormSuggestions($missingFields, $pageContext, $knowledgeIndex, $learningState, $profile)
                    : $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                        'What else do you need from me?',
                    ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.93,
            ];
        }

        $internetInfoResponse = $this->buildInternetInfoResponse($message, $normalized, $pageContext);
        if ($internetInfoResponse !== null) {
            return $internetInfoResponse;
        }

        $internetTopicResponse = $this->buildInternetTopicResponse($message, $normalized, $pageContext, $knowledgeIndex, $learningState, $internetKnowledge);
        if ($internetTopicResponse !== null) {
            return $internetTopicResponse;
        }

        $conversationalResponse = $this->buildConversationalResponse($message, $normalized, $pageContext, $memory, $conversation);
        if ($conversationalResponse !== null) {
            return $conversationalResponse;
        }

        $commonQuestionResponse = $this->buildCommonQuestionResponse($normalized, $learningState);
        if ($commonQuestionResponse !== null) {
            return $commonQuestionResponse;
        }

        $entries = $this->searchEntries($message, $siteId, $knowledgeIndex);
        if ($entries !== []) {
            return $this->buildSynthesizedSiteAnswer($message, $normalized, $entries, $pageContext, $knowledgeIndex, $learningState, $profile);
        }

        if ($this->looksLikeGeneralKnowledge($normalized)) {
            $messageText = 'That is a fun one. ';
            $messageText .= App::env('INTERACTIVE_AI_REMOTE_ENDPOINT')
                ? 'If you ask it a little differently, I can also lean on the connected AI service for broader answers.'
                : 'I am a little stronger on this website than on broad general knowledge, but I can still help you bounce back to what you need here.';

            return [
                'message' => $messageText,
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Tell me about this topic',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.35,
            ];
        }

        $learnedPrompt = $this->buildLearnedPrompt($learningState);

        return [
            'message' => 'I am still with you. We can keep chatting, or if you want, I can help you make sense of this page, find the right place, or sort out a form without making it feel like homework.' . ($learnedPrompt !== '' ? ' ' . $learnedPrompt : ''),
            'suggestions' => $this->buildFallbackSuggestions($learningState, $internetKnowledge),
            'actions' => $this->buildDefaultActions($pageContext, $profile),
            'confidence' => 0.30,
        ];
    }

    private function buildDefaultActions(array $pageContext, array $profile): array
    {
        $actions = [];

        if (!empty($pageContext['forms'][0])) {
            $prefill = $this->buildPrefillAction($pageContext['forms'][0], $profile);
            if ($prefill !== null) {
                $actions[] = $prefill;
                $actions[] = [
                    'type' => 'scroll_to_form',
                    'formIndex' => 0,
                    'label' => 'Go to the form',
                ];
            }
        }

        return $this->dedupeActions($actions);
    }

    private function buildSynthesizedSiteAnswer(
        string $message,
        string $normalized,
        array $entries,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        array $profile
    ): array {
        $topEntry = $entries[0];
        $topTitle = trim((string)($topEntry['title'] ?? 'this page'));
        $topUrl = trim((string)($topEntry['url'] ?? ''));
        $topSummary = trim((string)($topEntry['summary'] ?? ''));
        $alternatives = array_slice(array_values(array_filter(
            $entries,
            fn(array $entry): bool => trim((string)($entry['title'] ?? '')) !== $topTitle
        )), 0, 2);

        $messageText = $this->composeSiteAnswerMessage($message, $normalized, $topTitle, $topSummary, $alternatives);

        $actions = [];
        if ($topUrl !== '') {
            $actions[] = [
                'type' => 'navigate',
                'url' => $topUrl,
                'label' => $this->isNavigationIntent($normalized) ? "Open {$topTitle}" : "Go to {$topTitle}",
            ];
        }

        $actions = [...$actions, ...$this->buildDefaultActions($pageContext, $profile)];

        $seed = [
            $topUrl !== '' ? "Open {$topTitle}" : "Tell me more about {$topTitle}",
            'Show me something related',
        ];

        return [
            'message' => $messageText,
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, $seed, $profile),
            'actions' => $actions,
            'confidence' => 0.88,
        ];
    }

    private function composeSiteAnswerMessage(
        string $message,
        string $normalized,
        string $topTitle,
        string $topSummary,
        array $alternatives
    ): string {
        $leadIn = $this->chooseSiteLeadIn($normalized);
        $summary = $topSummary !== '' ? StringHelper::truncate($topSummary, 220) : '';

        if ($this->isNavigationIntent($normalized)) {
            $body = "{$topTitle} looks like the clearest place to go next.";
        } elseif ($this->isCurrentPageQuestion($normalized) || preg_match('/\b(what is|tell me about|explain)\b/i', $message)) {
            $body = "{$topTitle} is probably the closest match for what you are asking about.";
        } else {
            $body = "{$topTitle} looks like the strongest match I can see on the site.";
        }

        if ($summary !== '') {
            $body .= ' ' . $summary;
        }

        if ($alternatives !== []) {
            $body .= ' You might also want ' . $this->humanizeList(array_map(
                static fn(array $entry): string => trim((string)($entry['title'] ?? '')),
                $alternatives
            )) . '.';
        }

        return trim($leadIn . ' ' . $body);
    }

    private function chooseSiteLeadIn(string $message): string
    {
        if ($this->isNavigationIntent($message)) {
            $options = [
                'I can point you in the right direction.',
                'I think I have the right place for that.',
                'I can help with that.',
            ];

            return $options[array_rand($options)];
        }

        if ($this->isCountIntent($message)) {
            return 'Here is the clearest answer I can see.';
        }

        $options = [
            'I think this is the most useful lead.',
            'This looks like the best fit from the site.',
            'I found something that should help.',
        ];

        return $options[array_rand($options)];
    }

    private function buildPrefillAction(array $form, array $profile): ?array
    {
        $fieldMap = [];
        $profilePayload = $this->buildFormProfilePayload($profile);

        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string)($field['name'] ?? '');
            $label = mb_strtolower((string)($field['label'] ?? $field['placeholder'] ?? ''));
            $type = mb_strtolower((string)($field['type'] ?? ''));

            if ($name === '') {
                continue;
            }
            $value = $this->resolveProfileValueForField($field, $profilePayload, $label, $name, $type);
            if ($value !== null && $value !== '') {
                $fieldMap[$name] = $value;
            }
        }

        if ($fieldMap === []) {
            return null;
        }

        return [
            'type' => 'fill_form',
            'formIndex' => 0,
            'fields' => $fieldMap,
            'profile' => $profilePayload,
            'label' => 'Fill the form with my details',
        ];
    }

    private function buildFormProfilePayload(array $profile): array
    {
        $name = trim((string)($profile['name'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = trim((string)($profile['firstName'] ?? ($parts[0] ?? '')));
        $lastName = trim((string)($profile['lastName'] ?? (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '')));
        $bestName = $name !== '' ? $name : trim(implode(' ', array_filter([$firstName, $lastName])));

        return array_filter([
            'name' => $bestName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => trim((string)($profile['email'] ?? '')),
            'phone' => trim((string)($profile['phone'] ?? '')),
            'company' => trim((string)($profile['company'] ?? '')),
            'subject' => trim((string)($profile['subject'] ?? '')),
            'message' => trim((string)($profile['message'] ?? '')),
            'formFields' => $this->sanitizeCustomProfileFields((array)($profile['formFields'] ?? [])),
        ], static fn(mixed $value): bool => $value !== '');
    }

    private function buildFormSuggestions(array $missingFields, array $pageContext, array $knowledgeIndex, array $learningState, array $profile = []): array
    {
        $seed = [];

        foreach (array_slice($missingFields, 0, 2) as $field) {
            $seed[] = 'My ' . $field . ' is...';
        }

        $seed[] = 'Can you help me fill this in?';

        return $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, $seed, $profile);
    }

    private function buildSmartSuggestions(array $pageContext, array $knowledgeIndex = [], array $learningState = [], array $seed = [], array $profile = []): array
    {
        $suggestions = [];
        $currentTitle = $this->sanitizeResultTitle((string)($pageContext['title'] ?? ''));
        $currentUrl = trim((string)($pageContext['url'] ?? ''));

        foreach ($seed as $item) {
            $this->pushSuggestion($suggestions, $item);
        }

        if ($currentTitle !== '') {
            $this->pushSuggestion($suggestions, 'What is ' . $currentTitle . ' about?');
        } else {
            $this->pushSuggestion($suggestions, 'What is on this page?');
        }

        $links = $this->normalizeIndexedLinks((array)($pageContext['links'] ?? []));
        foreach (array_slice($links, 0, 2) as $link) {
            $text = trim((string)($link['text'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($text !== '' && !$this->looksLikeCurrentPageTarget($text, $url, $currentTitle, $currentUrl)) {
                $this->pushSuggestion($suggestions, 'Take me to ' . $text);
            }
        }

        if (!empty($pageContext['forms']) && $this->buildPrefillAction((array)$pageContext['forms'][0], $profile) !== null) {
            $this->pushSuggestion($suggestions, 'Fill the form with my details');
        }

        foreach ($this->buildIntentAwareSuggestions($pageContext, $knowledgeIndex, $learningState, $currentTitle, $currentUrl) as $suggestion) {
            $this->pushSuggestion($suggestions, $suggestion);
        }

        $sections = array_values(array_filter((array)($knowledgeIndex['sections'] ?? []), static fn(array $section): bool => ((int)($section['count'] ?? 0)) > 0));
        foreach (array_slice($sections, 0, 2) as $section) {
            $name = trim((string)($section['name'] ?? ''));
            if ($name !== '') {
                $this->pushSuggestion($suggestions, 'Show me ' . $name);
            }
        }

        foreach (array_slice($this->getCommonQuestions($learningState), 0, 2) as $question) {
            $this->pushSuggestion($suggestions, $question);
        }

        if ($suggestions === []) {
            $suggestions = [
                'What is on this page?',
                'Help me find something',
            ];
        }

        return array_slice($this->dedupeSuggestions($suggestions), 0, 4);
    }

    private function buildIntentAwareSuggestions(
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        string $currentTitle,
        string $currentUrl
    ): array {
        $suggestions = [];
        $recentTopics = array_slice(array_values(array_filter(array_map(
            static fn(mixed $topic): string => mb_strtolower(trim((string)$topic)),
            (array)($learningState['recentTopics'] ?? [])
        ))), -6);

        if ($recentTopics === []) {
            return [];
        }

        $visibleSections = array_values(array_filter((array)($knowledgeIndex['sections'] ?? []), static fn(array $section): bool => ((int)($section['count'] ?? 0)) > 0));
        foreach ($visibleSections as $section) {
            $name = trim((string)($section['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $keywords = array_map(
                static fn(mixed $keyword): string => mb_strtolower(trim((string)$keyword)),
                (array)($section['keywords'] ?? [])
            );

            foreach ($recentTopics as $topic) {
                if ($topic === '') {
                    continue;
                }

                if (str_contains(mb_strtolower($name), $topic) || in_array($topic, $keywords, true)) {
                    $suggestions[] = 'Show me ' . $name;
                    break;
                }
            }
        }

        $pages = array_values(array_filter((array)($knowledgeIndex['pages'] ?? []), static fn(array $page): bool => !empty($page['title']) && !empty($page['url'])));
        foreach ($pages as $page) {
            $title = trim((string)($page['title'] ?? ''));
            $url = trim((string)($page['url'] ?? ''));
            if ($title === '' || $url === '' || $this->looksLikeCurrentPageTarget($title, $url, $currentTitle, $currentUrl)) {
                continue;
            }

            $haystack = mb_strtolower(trim($title . ' ' . (string)($page['summary'] ?? '') . ' ' . implode(' ', (array)($page['headings'] ?? []))));
            foreach ($recentTopics as $topic) {
                if ($topic !== '' && str_contains($haystack, $topic)) {
                    $suggestions[] = 'Take me to ' . $title;
                    $suggestions[] = 'What is ' . $title . ' about?';
                    break;
                }
            }

            if (count($suggestions) >= 4) {
                break;
            }
        }

        foreach (array_slice($recentTopics, 0, 2) as $topic) {
            if ($topic !== '' && !$this->isGenericResultLabel($topic)) {
                $suggestions[] = 'Help me find ' . $topic;
            }
        }

        return $this->dedupeSuggestions($suggestions);
    }

    private function buildProfilePromptResponse(
        string $message,
        array $pageContext,
        array $profile,
        array $knowledgeIndex,
        array $learningState
    ): ?array {
        $firstForm = is_array($pageContext['forms'][0] ?? null) ? $pageContext['forms'][0] : null;
        if ($firstForm === null) {
            return null;
        }

        $promptField = $this->matchPromptFieldFromForm($message, $firstForm);
        if ($promptField === null) {
            return null;
        }

        $profileKey = $this->inferProfileKeyFromField($promptField);
        $fieldLabel = $this->normalizeSuggestionLabelFragment((string)($promptField['label'] ?? $promptField['placeholder'] ?? $promptField['name'] ?? 'this detail'));
        $followUpSuggestions = $this->buildNextFieldSuggestions($firstForm, $profileKey, $profile, $knowledgeIndex, $learningState, $pageContext);

        if ($profileKey !== null && $this->hasStoredProfileValue($profile, $profileKey)) {
            return [
                'message' => 'I already have your ' . mb_strtolower($fieldLabel) . ' saved, so I can use it in the form whenever you want.',
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Fill the form with my details',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.95,
            ];
        }

        return [
            'message' => 'I am ready for your ' . mb_strtolower($fieldLabel) . '. Just type it after "' . $message . '", and I will keep it ready for the form.',
            'suggestions' => $followUpSuggestions,
            'actions' => $this->buildDefaultActions($pageContext, $profile),
            'confidence' => 0.96,
        ];
    }

    private function buildProfileCaptureResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $profile,
        array $knowledgeIndex,
        array $learningState
    ): ?array {
        $firstForm = is_array($pageContext['forms'][0] ?? null) ? $pageContext['forms'][0] : null;
        if ($firstForm === null) {
            return null;
        }

        $matchedField = $this->matchCompletedProfileField($message, $normalized, $firstForm, $profile);
        if ($matchedField === null) {
            return null;
        }

        $profileKey = $this->inferProfileKeyFromField($matchedField);
        if ($profileKey === null || !$this->hasStoredProfileValue($profile, $profileKey)) {
            return null;
        }

        $requiredFields = $this->getRequiredFields($firstForm);
        $missingFields = $this->getMissingProfileFields($requiredFields, $profile);
        $fieldLabel = mb_strtolower($this->normalizeSuggestionLabelFragment((string)($matchedField['label'] ?? $matchedField['placeholder'] ?? $matchedField['name'] ?? $profileKey)));

        if ($missingFields === []) {
            return [
                'message' => 'Perfect, I have your ' . $fieldLabel . ' now. I have enough to fill the form whenever you are ready.',
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Fill the form with my details',
                    'Go to the form',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.98,
            ];
        }

        return [
            'message' => 'Perfect, I have your ' . $fieldLabel . ' now. Next, I still need your ' . $this->humanizeList($missingFields) . ' to complete the form properly.',
            'suggestions' => $this->buildNextFieldSuggestions($firstForm, $profileKey, $profile, $knowledgeIndex, $learningState, $pageContext),
            'actions' => $this->buildDefaultActions($pageContext, $profile),
            'confidence' => 0.97,
        ];
    }

    private function buildFormCorrectionResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $profile,
        array $knowledgeIndex,
        array $learningState
    ): ?array {
        $firstForm = is_array($pageContext['forms'][0] ?? null) ? $pageContext['forms'][0] : null;
        if ($firstForm === null) {
            return null;
        }

        if (!preg_match('/\b(empty|blank|missing)\b/i', $message)) {
            return null;
        }

        $matchedField = $this->matchFieldMentionFromForm($message, $firstForm);
        if ($matchedField === null) {
            return null;
        }

        $profileKey = $this->inferProfileKeyFromField($matchedField);
        $fieldLabel = mb_strtolower($this->normalizeSuggestionLabelFragment((string)($matchedField['label'] ?? $matchedField['placeholder'] ?? $matchedField['name'] ?? 'this field')));
        $storedValue = $this->getStoredProfileValue($profile, $profileKey);

        if ($storedValue !== null && $storedValue !== '') {
            return [
                'message' => 'I can see the ' . $fieldLabel . ' still needs attention. I already have that detail saved, so I can try filling it in again.',
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Fill the form with my details',
                    'Go to the form',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.98,
            ];
        }

        return [
            'message' => 'I can see the ' . $fieldLabel . ' is still empty. If you send me that detail, I will keep it ready for the form.',
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'My ' . $fieldLabel . ' is...',
            ], $profile),
            'actions' => $this->buildDefaultActions($pageContext, $profile),
            'confidence' => 0.97,
        ];
    }

    private function matchCompletedProfileField(string $message, string $normalized, array $form, array $profile): ?array
    {
        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = $this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? ''));
            if ($label === '') {
                continue;
            }

            $promptPrefix = 'my ' . mb_strtolower($label) . ' is ';
            if (str_starts_with($normalized, $promptPrefix)) {
                return $field;
            }
        }

        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $profileKey = $this->inferProfileKeyFromField($field);
            if ($profileKey === null || !$this->hasStoredProfileValue($profile, $profileKey)) {
                continue;
            }

            $value = mb_strtolower(trim((string)$this->getStoredProfileValue($profile, $profileKey)));
            if ($value !== '' && str_contains($normalized, $value)) {
                return $field;
            }
        }

        return null;
    }

    private function matchPromptFieldFromForm(string $message, array $form): ?array
    {
        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = $this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? ''));
            if ($label === '') {
                continue;
            }

            $prompt = 'my ' . mb_strtolower($label) . ' is';
            if ($message === $prompt) {
                return $field;
            }
        }

        return null;
    }

    private function matchFieldMentionFromForm(string $message, array $form): ?array
    {
        $normalizedMessage = mb_strtolower($this->normalizeSuggestionLabelFragment($message));
        $genericNeedle = $this->inferGenericFieldNeedle($normalizedMessage);

        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = mb_strtolower($this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? '')));
            if ($label === '') {
                continue;
            }

            if (str_contains($normalizedMessage, $label)) {
                return $field;
            }

            if ($genericNeedle !== null) {
                $profileKey = $this->inferProfileKeyFromField($field);
                if ($profileKey === $genericNeedle) {
                    return $field;
                }

                if (
                    $genericNeedle === 'name'
                    && in_array($profileKey, ['name', 'firstName', 'lastName'], true)
                ) {
                    return $field;
                }
            }
        }

        return null;
    }

    private function inferGenericFieldNeedle(string $message): ?string
    {
        return match (true) {
            preg_match('/\b(first name|firstname)\b/i', $message) === 1 => 'firstName',
            preg_match('/\b(last name|lastname|surname)\b/i', $message) === 1 => 'lastName',
            preg_match('/\b(name field|name)\b/i', $message) === 1 => 'name',
            preg_match('/\b(email field|email|email address)\b/i', $message) === 1 => 'email',
            preg_match('/\b(phone field|phone|mobile|telephone)\b/i', $message) === 1 => 'phone',
            preg_match('/\b(company field|company|organisation|organization)\b/i', $message) === 1 => 'company',
            preg_match('/\b(subject field|subject)\b/i', $message) === 1 => 'subject',
            preg_match('/\b(message field|message|comment|enquiry|inquiry)\b/i', $message) === 1 => 'message',
            default => null,
        };
    }

    private function inferProfileKeyFromField(array $field): ?string
    {
        $label = mb_strtolower((string)($field['label'] ?? $field['placeholder'] ?? ''));
        $name = mb_strtolower((string)($field['name'] ?? ''));
        $placeholder = mb_strtolower((string)($field['placeholder'] ?? ''));
        $type = mb_strtolower((string)($field['type'] ?? ''));
        $haystack = $label . ' ' . $name . ' ' . $placeholder . ' ' . $type;

        return match (true) {
            str_contains($haystack, 'first name') => 'firstName',
            str_contains($haystack, 'last name') => 'lastName',
            str_contains($haystack, 'full name'),
            str_contains($haystack, 'your name'),
            preg_match('/(^|\s)name(\s|$)/', $haystack) === 1 => 'name',
            str_contains($haystack, 'email') || $type === 'email' => 'email',
            str_contains($haystack, 'phone'),
            str_contains($haystack, 'mobile'),
            $type === 'tel' => 'phone',
            str_contains($haystack, 'company'),
            str_contains($haystack, 'organisation'),
            str_contains($haystack, 'organization') => 'company',
            str_contains($haystack, 'subject') => 'subject',
            str_contains($haystack, 'message'),
            str_contains($haystack, 'comment'),
            str_contains($haystack, 'enquiry'),
            str_contains($haystack, 'inquiry') => 'message',
            default => 'field:' . $this->normalizeFormFieldProfileKey((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? '')),
        };
    }

    private function normalizeFormFieldProfileKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\*\:\(\)\[\]\{\}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function sanitizeCustomProfileFields(array $fields): array
    {
        $sanitized = [];

        foreach ($fields as $key => $value) {
            $normalizedKey = $this->normalizeFormFieldProfileKey((string)$key);
            $normalizedValue = trim((string)$value);

            if ($normalizedKey === '' || $normalizedValue === '') {
                continue;
            }

            $sanitized[$normalizedKey] = $normalizedValue;
        }

        return $sanitized;
    }

    private function getStoredProfileValue(array $profile, ?string $profileKey): ?string
    {
        if ($profileKey === null || $profileKey === '') {
            return null;
        }

        if (str_starts_with($profileKey, 'field:')) {
            $fieldKey = substr($profileKey, 6);
            $formFields = $this->sanitizeCustomProfileFields((array)($profile['formFields'] ?? []));

            return isset($formFields[$fieldKey]) ? trim((string)$formFields[$fieldKey]) : null;
        }

        $value = trim((string)($profile[$profileKey] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function hasStoredProfileValue(array $profile, ?string $profileKey): bool
    {
        return $this->getStoredProfileValue($profile, $profileKey) !== null;
    }

    private function resolveProfileValueForField(array $field, array $profilePayload, string $label, string $name, string $type): ?string
    {
        $aliases = array_values(array_filter([
            $this->normalizeFormFieldProfileKey($label),
            $this->normalizeFormFieldProfileKey($name),
            $this->normalizeFormFieldProfileKey((string)($field['placeholder'] ?? '')),
        ]));

        foreach ($aliases as $alias) {
            if (!empty($profilePayload['formFields'][$alias])) {
                return trim((string)$profilePayload['formFields'][$alias]);
            }
        }

        if ((str_contains($label, 'first name') || str_contains($name, 'first_name') || $name === 'firstname') && !empty($profilePayload['firstName'])) {
            return $profilePayload['firstName'];
        }

        if ((str_contains($label, 'last name') || str_contains($name, 'last_name') || $name === 'lastname') && !empty($profilePayload['lastName'])) {
            return $profilePayload['lastName'];
        }

        if (str_contains($label, 'full name') || str_contains($label, 'your name') || preg_match('/(^|\s)name(\s|$)/', $label . ' ' . $name) === 1) {
            $bestName = trim((string)($profilePayload['name'] ?? ''));
            if ($bestName !== '') {
                return $bestName;
            }
        }

        if ((str_contains($label, 'email') || $type === 'email') && !empty($profilePayload['email'])) {
            return $profilePayload['email'];
        }

        if ((str_contains($label, 'phone') || str_contains($name, 'phone') || str_contains($label, 'mobile') || $type === 'tel') && !empty($profilePayload['phone'])) {
            return $profilePayload['phone'];
        }

        if ((str_contains($label, 'company') || str_contains($label, 'organisation') || str_contains($label, 'organization') || str_contains($name, 'company')) && !empty($profilePayload['company'])) {
            return $profilePayload['company'];
        }

        if ((str_contains($label, 'subject') || str_contains($name, 'subject')) && !empty($profilePayload['subject'])) {
            return $profilePayload['subject'];
        }

        if ((str_contains($label, 'message') || str_contains($label, 'comment') || str_contains($label, 'enquiry') || str_contains($label, 'inquiry')) && !empty($profilePayload['message'])) {
            return $profilePayload['message'];
        }

        return null;
    }

    private function buildNextFieldSuggestions(
        array $form,
        ?string $currentProfileKey,
        array $profile,
        array $knowledgeIndex,
        array $learningState,
        array $pageContext
    ): array {
        $seed = [];

        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $profileKey = $this->inferProfileKeyFromField($field);
            if ($profileKey === null || $profileKey === $currentProfileKey || $this->hasStoredProfileValue($profile, $profileKey)) {
                continue;
            }

            $label = $this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? ''));
            if ($label === '') {
                continue;
            }

            $seed[] = 'My ' . mb_strtolower($label) . ' is...';

            if (count($seed) >= 2) {
                break;
            }
        }

        if ($seed === []) {
            $seed[] = 'Fill the form with my details';
        }

        return $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, $seed, $profile);
    }

    private function buildFormSuggestionLabel(array $form, array $pageContext = []): string
    {
        $fields = array_slice(array_values(array_filter(array_map(
            fn(array $field): string => $this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? '')),
            array_filter((array)($form['fields'] ?? []), 'is_array')
        ))), 0, 2);

        $pageAnchor = $this->normalizeSuggestionLabelFragment((string)($pageContext['title'] ?? ''));
        if ($pageAnchor === '') {
            $headings = array_values(array_filter(array_map(
                fn(mixed $heading): string => $this->normalizeSuggestionLabelFragment((string)$heading),
                (array)($pageContext['headings'] ?? [])
            )));
            $pageAnchor = $headings[0] ?? '';
        }

        if ($pageAnchor !== '' && mb_strlen($pageAnchor) <= 36) {
            return 'Help me with the form on ' . $pageAnchor;
        }

        if ($fields !== []) {
            return 'Help me with these details: ' . $this->humanizeList($fields);
        }

        return 'Help me with the form on this page';
    }

    private function pushSuggestion(array &$suggestions, string $label): void
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($label === '' || mb_strlen($label) < 6) {
            return;
        }

        if ($this->isLowValueSuggestion($label)) {
            return;
        }

        if (!in_array($label, $suggestions, true)) {
            $suggestions[] = StringHelper::truncate($label, 64, '');
        }
    }

    private function dedupeSuggestions(array $suggestions): array
    {
        $unique = [];
        $seen = [];

        foreach ($suggestions as $suggestion) {
            $normalized = $this->normalizeUiOption($suggestion);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $suggestion;
        }

        return $unique;
    }

    private function dedupeActions(array $actions): array
    {
        $unique = [];
        $seen = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $normalized = $this->normalizeUiOption((string)($action['label'] ?? $action['type'] ?? ''));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $unique[] = $action;
        }

        return $unique;
    }

    private function normalizeUiOption(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function looksLikeCurrentPageTarget(string $text, string $url, string $currentTitle, string $currentUrl): bool
    {
        $normalizedText = $this->normalizeUiOption($text);
        $normalizedTitle = $this->normalizeUiOption($currentTitle);

        if ($normalizedText !== '' && $normalizedText === $normalizedTitle) {
            return true;
        }

        if ($url !== '' && $currentUrl !== '') {
            $cleanUrl = strtok($url, '#') ?: $url;
            $cleanCurrentUrl = strtok($currentUrl, '#') ?: $currentUrl;

            if ($cleanUrl === $cleanCurrentUrl) {
                return true;
            }
        }

        return $normalizedText === 'home' && str_contains($normalizedTitle, 'home');
    }

    private function isLowValueSuggestion(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        $genericPhrases = [
            'open name',
            'take me to name',
            'show me name',
            'open company',
            'open role',
            'open message',
            'show me company',
            'show me role',
        ];

        if (in_array($normalized, $genericPhrases, true)) {
            return true;
        }

        foreach (['open ', 'take me to ', 'show me '] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $target = trim(substr($normalized, strlen($prefix)));
                if ($this->isGenericResultLabel($target)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeSuggestionLabelFragment(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\*\:\_\-\|]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\b(required|optional)\b/i', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        $value = preg_replace('/\s+([,.;:!?])/u', '$1', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B.,");
    }

    private function summarizePageContext(array $pageContext): string
    {
        $title = $this->cleanExtractedText((string)($pageContext['title'] ?? ''));
        $headings = array_values(array_filter(array_map(
            fn(mixed $heading): string => $this->cleanExtractedText((string)$heading),
            (array)($pageContext['headings'] ?? [])
        )));
        $excerpt = $this->cleanExtractedText((string)($pageContext['contentExcerpt'] ?? ''));
        $settings = \kdgraphics\interactiveai\InteractiveAi::$plugin->getSettings();
        $sentenceCount = (int)($settings->summarySentences ?? 3);
        $llamaSummary = InteractiveAi::$plugin->getLlamaCpp()->summarize(
            implode(' ', [$title, implode(' ', $headings), $excerpt]),
            $title,
            $sentenceCount
        );
        if (is_string($llamaSummary) && trim($llamaSummary) !== '') {
            return trim($llamaSummary);
        }

        $aiSummary = \kdgraphics\interactiveai\InteractiveAi::$plugin->getSummarizer()->summarizeText(
            implode(' ', [$title, implode(' ', $headings), $excerpt]),
            $title,
            $sentenceCount
        );
        if (is_string($aiSummary) && trim($aiSummary) !== '') {
            return trim($aiSummary);
        }

        $parts = [];

        if ($title !== '') {
            $parts[] = "You are on {$title}.";
        }

        if ($headings !== []) {
            $parts[] = 'Key topics here include ' . implode(', ', array_slice($headings, 0, 4)) . '.';
        }

        if ($excerpt !== '') {
            $parts[] = StringHelper::truncate($excerpt, 260);
        }

        if (!empty($pageContext['forms'])) {
            $parts[] = 'If you would like to get in touch or send details, I can help with the form on this page.';
        }

        return implode(' ', $parts);
    }

    /**
     * @throws InvalidConfigException
     */
    private function searchEntries(string $message, int $siteId, ?array $knowledgeIndex = null): array
    {
        if ($knowledgeIndex !== null) {
            $indexedResults = $this->searchKnowledgeIndex($message, $knowledgeIndex);
            if ($indexedResults !== []) {
                return $indexedResults;
            }
        }

        $keywords = $this->extractKeywords($message);
        if ($keywords === '') {
            return [];
        }

        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->status(null)
            ->search($keywords)
            ->limit(5)
            ->all();

        $results = [];
        foreach ($entries as $entry) {
            $record = [
                'title' => $this->sanitizeResultTitle($entry->title),
                'url' => $entry->getUrl(),
                'section' => $entry->getSection()?->name,
                'summary' => $this->buildEntrySummary($entry),
            ];

            if ($this->isUsefulResultRecord($record)) {
                $results[] = $record;
            }
        }

        return $results;
    }

    private function buildEntrySummary(Entry $entry): string
    {
        $values = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $entry->getFieldValue($field->handle);
            $text = $this->normalizeFieldValue($value);

            if ($text !== '') {
                $values[] = $text;
            }
        }

        $summary = implode(' ', $values);
        $summary = $this->cleanExtractedText($summary);
        $settings = \kdgraphics\interactiveai\InteractiveAi::$plugin->getSettings();
        $sentenceCount = (int)($settings->summarySentences ?? 3);
        $entryTitle = trim((string)($entry->title ?? ''));
        $summary = \kdgraphics\interactiveai\InteractiveAi::$plugin->getSummarizer()->summarizeText($summary, $entryTitle, $sentenceCount)
            ?: $this->sanitizeSummaryText($summary);

        return StringHelper::truncate(trim($summary), 220);
    }

    private function normalizeFieldValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return implode(' ', array_map(fn(mixed $item): string => $this->normalizeFieldValue($item), $value));
        }

        if (is_object($value)) {
            if ($value instanceof \Stringable || method_exists($value, '__toString')) {
                $stringValue = (string)$value;

                if ($this->looksLikeInternalObjectString($stringValue)) {
                    return '';
                }

                return $stringValue;
            }

            if (method_exists($value, 'all')) {
                try {
                    $items = $value->all();
                    return $this->normalizeFieldValue($items);
                } catch (\Throwable) {
                    return '';
                }
            }
        }

        return '';
    }

    private function looksLikeInternalObjectString(string $value): bool
    {
        $value = trim($value);

        return $value === ''
            || str_contains($value, 'craft\\')
            || str_contains($value, 'yii\\')
            || str_contains($value, 'ElementQuery')
            || str_contains($value, 'Object(');
    }

    private function sanitizeSummaryText(string $summary): string
    {
        $summary = $this->cleanExtractedText($summary);
        if ($this->looksLikePromptLeakageText($summary)) {
            return '';
        }
        $summary = preg_replace('/\b(?:craft|yii)\\\\[A-Za-z0-9_\\\\]+\b/', ' ', $summary) ?? $summary;
        $summary = preg_replace('/\bElementQuery\b/', ' ', $summary) ?? $summary;
        $summary = preg_replace('/\bComapny Role\b/i', ' ', $summary) ?? $summary;
        $summary = preg_replace('/\s+/', ' ', $summary) ?? $summary;

        $genericFragments = [
            'It is a long established fact that a reader will be distracted by the readable content of a page when looking at its layout.',
        ];

        foreach ($genericFragments as $fragment) {
            if (str_contains($summary, $fragment)) {
                $summary = str_replace($fragment, '', $summary);
            }
        }

        return trim($summary, " \t\n\r\0\x0B.,");
    }

    private function looksLikePromptLeakageText(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
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
            . '|recent conversation:'
            . '|available follow-up options:'
            . '|known site sections:'
            . '|desired emotional tone:'
            . '|intent guidance:'
            . '|\|\s*user:'
            . '|\|\s*assistant:'
            . '|final reply:'
            . '|better reply:'
            . ')\b/i',
            $text
        );
    }

    private function cleanExtractedText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\b(?:craft|yii)\\\\[A-Za-z0-9_\\\\]+\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\bElementQuery\b/', ' ', $text) ?? $text;
        $text = preg_replace('/#[0-9a-fA-F]{3,8}\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:rgba?|hsla?)\([^\)]*\)/i', ' ', $text) ?? $text;
        $text = preg_replace('/\b[a-z-]{2,}\s*:\s*[^;]{1,40};?/i', ' ', $text) ?? $text;
        $text = preg_replace('/\bvar\(--[A-Za-z0-9\-_]+\)\b/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return $this->stripLowSignalSegments($text);
    }

    private function stripLowSignalSegments(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $segments = preg_split('/(?<=[\.\!\?])\s+|\s{2,}/u', $text) ?: [];
        $kept = [];

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t\n\r\0\x0B.,;:|\\/");
            if ($segment === '' || $this->looksLikeLowSignalSegment($segment)) {
                continue;
            }

            $kept[] = $segment;
        }

        if ($kept === []) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', implode('. ', $kept)) ?? implode('. ', $kept));
    }

    private function looksLikeLowSignalSegment(string $segment): bool
    {
        $plain = trim($segment);
        if ($plain === '') {
            return true;
        }

        $lower = mb_strtolower($plain);

        if (preg_match('/\blorem ipsum\b/i', $plain)) {
            return true;
        }

        if (preg_match('/#[0-9a-f]{3,8}\b/i', $plain)) {
            return true;
        }

        if (preg_match('/\b(?:craft|yii)\\\\/i', $plain) || str_contains($plain, 'ElementQuery')) {
            return true;
        }

        if (preg_match('/^[^A-Za-z0-9]*$/', $plain)) {
            return true;
        }

        $tokens = preg_split('/\s+/', $plain) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

        if ($tokens === []) {
            return true;
        }

        $noisy = 0;
        foreach ($tokens as $token) {
            $symbolCount = preg_match_all('/[^A-Za-z0-9]/', $token);
            $length = max(1, mb_strlen($token));
            if ($symbolCount >= 2 && ($symbolCount / $length) > 0.25) {
                $noisy++;
            }
        }

        if ($noisy > 0 && $noisy >= (int)ceil(count($tokens) * 0.5)) {
            return true;
        }

        $letters = preg_match_all('/[A-Za-z]/', $plain);
        $numbers = preg_match_all('/[0-9]/', $plain);
        if ($letters < 6 && $numbers > $letters) {
            return true;
        }

        $genericFragments = [
            'it is a long established fact that a reader will be distracted by the readable content of a page when looking at its layout',
            'the point of using lorem ipsum',
        ];

        foreach ($genericFragments as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeResultTitle(?string $title): string
    {
        $title = trim((string)$title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title, " \t\n\r\0\x0B.,");
    }

    private function isUsefulResultRecord(array $record, bool $allowGenericTitles = false): bool
    {
        $title = trim((string)($record['title'] ?? ''));
        $summary = trim((string)($record['summary'] ?? ''));

        if ($title === '') {
            return false;
        }

        if (!$allowGenericTitles && $this->isGenericResultLabel($title)) {
            return false;
        }

        if ($summary !== '' && $this->looksLikeInternalObjectString($summary)) {
            return false;
        }

        return true;
    }

    private function isGenericResultLabel(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return in_array($normalized, [
            'name',
            'title',
            'email',
            'phone',
            'message',
            'company',
            'role',
            'form',
            'page',
        ], true);
    }

    private function extractKeywords(string $message): string
    {
        $message = mb_strtolower($message);
        $message = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message) ?? '';
        $parts = preg_split('/\s+/', trim($message)) ?: [];
        $stopWords = [
            'a', 'an', 'and', 'are', 'about', 'for', 'from', 'help', 'how', 'i', 'is', 'me', 'my', 'need',
            'of', 'on', 'open', 'page', 'please', 'show', 'site', 'take', 'tell', 'that', 'the', 'this',
            'to', 'want', 'where', 'with', 'you',
        ];

        $filtered = array_values(array_filter($parts, static function(string $part) use ($stopWords): bool {
            return mb_strlen($part) > 2 && !in_array($part, $stopWords, true);
        }));

        $filtered = array_values(array_filter($filtered, fn(string $part): bool => !$this->isGenericResultLabel($part)));

        return implode(' ', array_slice($filtered, 0, 8));
    }

    private function getRequiredFields(array $form): array
    {
        $required = [];

        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['required'])) {
                continue;
            }

            $required[] = mb_strtolower((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? 'field'));
        }

        return $required;
    }

    private function getMissingProfileFields(array $requiredFields, array $profile): array
    {
        $customFields = $this->sanitizeCustomProfileFields((array)($profile['formFields'] ?? []));

        $missing = [];
        foreach ($requiredFields as $field) {
            $normalizedField = $this->normalizeFormFieldProfileKey((string)$field);
            if ($normalizedField === '') {
                continue;
            }

            $present = match (true) {
                str_contains($normalizedField, 'first name') => !empty($profile['firstName']),
                str_contains($normalizedField, 'last name') => !empty($profile['lastName']),
                preg_match('/(^|\s)name(\s|$)/', $normalizedField) === 1 => !empty($profile['name']),
                str_contains($normalizedField, 'email') => !empty($profile['email']),
                str_contains($normalizedField, 'phone'),
                str_contains($normalizedField, 'mobile') => !empty($profile['phone']),
                str_contains($normalizedField, 'company'),
                str_contains($normalizedField, 'organisation'),
                str_contains($normalizedField, 'organization') => !empty($profile['company']),
                str_contains($normalizedField, 'subject') => !empty($profile['subject']),
                str_contains($normalizedField, 'message'),
                str_contains($normalizedField, 'comment'),
                str_contains($normalizedField, 'enquiry'),
                str_contains($normalizedField, 'inquiry') => !empty($profile['message']),
                default => !empty($customFields[$normalizedField]),
            };

            if (!$present) {
                $missing[] = $normalizedField;
            }
        }

        return array_values(array_unique($missing));
    }

    private function formatFieldList(array $form): string
    {
        $labels = [];
        foreach ((array)($form['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = $this->normalizeSuggestionLabelFragment((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        $labels = array_values(array_filter($labels));

        $labels = array_slice($labels, 0, 4);

        return $this->humanizeList($labels);
    }

    private function humanizeList(array $items): string
    {
        $items = array_values(array_filter(array_map(static fn(mixed $item): string => trim((string)$item), $items)));

        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        if (count($items) === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ', and ' . $last;
    }

    private function buildInternetInfoResponse(string $message, string $normalized, array $pageContext = []): ?array
    {
        if ($this->isTimeZoneIntent($normalized)) {
            $timezone = $this->getDefaultTimezone($pageContext);

            return [
                'message' => "Your current time zone looks like {$timezone}. If you want, I can also tell you the current time in a specific city.",
                'suggestions' => [
                    'What is the current time?',
                    'What time is it in London?',
                    'What is the weather in London?',
                ],
                'actions' => [],
                'confidence' => 0.96,
            ];
        }

        if ($this->isLocationUtilityIntent($normalized)) {
            return [
                'message' => 'If you want a location-based answer, tell me the city or area you mean. For example, you can ask for the time or weather in a specific place.',
                'suggestions' => [
                    'What time is it in London?',
                    'Weather in Edinburgh',
                    'What is on this page?',
                ],
                'actions' => [],
                'confidence' => 0.9,
            ];
        }

        if ($this->isWeatherIntent($normalized)) {
            $location = $this->extractLocation($message);

            if ($location === null) {
                return [
                    'message' => 'I can help with the weather. Just tell me the city or area, like "weather in Edinburgh" or "weather in Mumbai".',
                    'suggestions' => [
                        'Weather in London',
                        'Weather in New York',
                        'Weather in Mumbai',
                    ],
                    'actions' => [],
                    'confidence' => 0.95,
                ];
            }

            $weather = $this->fetchWeather($location);
            if ($weather !== null) {
                return [
                    'message' => $weather,
                    'suggestions' => [
                        'What time is it there?',
                        'What is the date there?',
                        'Tell me about this page',
                    ],
                    'actions' => [],
                    'confidence' => 0.95,
                ];
            }

            return [
                'message' => 'I could not get the weather just now, but if you try again with a city name I can have another go.',
                'suggestions' => [
                    'Weather in Glasgow',
                    'Weather in Delhi',
                    'What time is it there?',
                ],
                'actions' => [],
                'confidence' => 0.6,
            ];
        }

        if ($this->isTimeIntent($normalized) || $this->isDateIntent($normalized)) {
            $location = $this->extractLocation($message);
            $timeInfo = $this->buildTimeDateResponse($normalized, $location, $pageContext);

            if ($timeInfo !== null) {
                return [
                    'message' => $timeInfo,
                    'suggestions' => [
                        $location ? "Weather in {$location}" : 'What is the weather in London?',
                        'Tell me about this page',
                        'Show me something on this site',
                    ],
                    'actions' => [],
                    'confidence' => 0.96,
                ];
            }
        }

        return null;
    }

    private function buildInternetTopicResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        array $internetKnowledge
    ): ?array {
        if ($this->isCurrentPageQuestion($normalized) || $this->isFormIntent($normalized) || $this->isNavigationIntent($normalized)) {
            return null;
        }

        $topic = $this->extractInternetTopic($message);
        if ($topic === null) {
            return null;
        }

        $summary = $this->getInternetKnowledgeSummary($topic, $internetKnowledge);
        if ($summary === null) {
            return null;
        }

        return [
            'message' => $summary,
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Tell me a bit more about ' . $topic,
            ]),
            'actions' => $this->buildDefaultActions($pageContext, []),
            'confidence' => 0.74,
        ];
    }

    private function buildConversationalResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $memory,
        array $conversation
    ): ?array {
        if ($this->isStuckIntent($normalized)) {
            return [
                'message' => 'I understand. We can slow it down and take this one step at a time. Tell me what feels stuck, and I will help you work through it.',
                'suggestions' => [
                    'Help me with this form',
                    'What is on this page?',
                    'Show me somewhere useful',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.97,
            ];
        }

        if ($this->isJokeIntent($normalized)) {
            $jokes = [
                'Alright, mood lifted. *Why did the webpage go to therapy? It had too many unresolved issues.*',
                'Okay, here is one. *Why did the form feel confident? Because it finally had all the right fields.*',
                'Happily. *Why do websites love good assistants? Because we help people get to the point a little faster.*',
            ];
            $joke = $this->pickNonRepeatingJoke($jokes, $conversation);

            return [
                'message' => $joke . ' If you want, I can keep the jokes coming, or I can help you with something on the site while I am in a good mood.',
                'suggestions' => [
                    'Tell me another joke',
                    'What is on this page?',
                    'Help me find something here',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.96,
            ];
        }

        if ($this->isThanksIntent($normalized)) {
            return [
                'message' => 'That is sweet of you. I am always happy to help, whether you want a quick answer or a gentle nudge in the right direction.',
                'suggestions' => [
                    'What is on this page?',
                    'Show me somewhere useful',
                    'Help me with the form here',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.96,
            ];
        }

        if ($this->isHowAreYouIntent($normalized)) {
            $tail = !empty($memory['preferredContact'])
                ? ' I am keeping a few helpful preferences in mind as we go.'
                : '';

            return [
                'message' => 'I am doing well, thank you for asking.' . $tail . ' I am in a helpful mood, so where shall we go next?',
                'suggestions' => [
                    'Tell me a joke',
                    'What is on this page?',
                    'Show me somewhere useful',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.94,
            ];
        }

        if ($this->isWhoAreYouIntent($normalized)) {
            $siteName = Craft::$app->getSites()->getCurrentSite()->name;

            return [
                'message' => "I am the interactive assistant for {$siteName}. Think of me as a friendly guide who can chat, point you in the right direction, and make the website feel a little easier to navigate.",
                'suggestions' => [
                    'What can you help with?',
                    'What is on this page?',
                    'Help me find something here',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.95,
            ];
        }

        if ($this->isCapabilityIntent($normalized)) {
            return [
                'message' => 'Quite a few things, actually. I can help you understand the current page, find content across the website, guide you to the right place, help with forms, and answer simple things like time, date, and weather. I can also keep the conversation a little lighter along the way.',
                'suggestions' => [
                    'What is on this page?',
                    'Show me something useful',
                    'What is the weather in London?',
                ],
                'actions' => $this->buildDefaultActions($pageContext, []),
                'confidence' => 0.95,
            ];
        }

        return null;
    }

    private function pickNonRepeatingJoke(array $jokes, array $conversation): string
    {
        $recentMatchedIndexes = [];
        $lastMatchedIndex = null;

        foreach (array_reverse($conversation) as $item) {
            if (!is_array($item) || ($item['role'] ?? null) !== 'assistant') {
                continue;
            }

            $content = $this->normalizeJokeText(trim((string)($item['content'] ?? '')));
            if ($content === '') {
                continue;
            }

            foreach ($jokes as $index => $joke) {
                $normalizedJoke = $this->normalizeJokeText($joke);
                if ($normalizedJoke !== '' && str_contains($content, $normalizedJoke)) {
                    $recentMatchedIndexes[] = $index;
                    if ($lastMatchedIndex === null) {
                        $lastMatchedIndex = $index;
                    }
                    break;
                }
            }

            if (count($recentMatchedIndexes) >= 4) {
                break;
            }
        }

        $recentMatchedIndexes = array_values(array_unique($recentMatchedIndexes));
        $count = count($jokes);

        if ($count === 0) {
            return '';
        }

        $startIndex = $lastMatchedIndex !== null ? (($lastMatchedIndex + 1) % $count) : 0;

        for ($offset = 0; $offset < $count; $offset++) {
            $candidateIndex = ($startIndex + $offset) % $count;
            if (!in_array($candidateIndex, $recentMatchedIndexes, true)) {
                return $jokes[$candidateIndex];
            }
        }

        return $jokes[$startIndex];
    }

    private function normalizeJokeText(string $text): string
    {
        $text = $this->stripInlineEmphasis($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function buildProfileResetResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState
    ): ?array {
        if (!$this->isProfileResetIntent($normalized)) {
            return null;
        }

        return [
            'message' => $this->blendGreeting($message, 'Done. I have cleared the saved form details from this browser session, so we can start fresh whenever you like.'),
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Help me fill in the form',
                'What is on this page?',
            ], []),
            'actions' => [
                [
                    'type' => 'clear_profile',
                    'label' => 'Clear my saved details',
                ],
            ],
            'confidence' => 0.99,
        ];
    }

    private function buildSelfResponse(
        string $message,
        string $normalized,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        array $profile,
        array $memory
    ): ?array {
        $siteName = Craft::$app->getSites()->getCurrentSite()->name;
        $assistantName = $this->getAssistantDisplayName();

        if ($this->isNameQuestion($normalized)) {
            return [
                'message' => $this->blendGreeting($message, "I am {$assistantName}. I am the interactive assistant here on {$siteName}, and you can think of me as your guide while you are exploring the site."),
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'Who are you?',
                    'What can you help with?',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.98,
            ];
        }

        if ($this->isWhoAreYouIntent($normalized)) {
            $messageText = "I am {$assistantName}, the interactive assistant for {$siteName}. I am here to chat naturally, help you find your way around, and make things like forms and pages feel easier.";
            if (!empty($memory['preferredContact'])) {
                $messageText .= ' I also try to remember helpful details while we chat so I can be a little more useful as we go.';
            }

            return [
                'message' => $this->blendGreeting($message, $messageText),
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'What can you help with?',
                    'Are you learning?',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.98,
            ];
        }

        if ($this->isLearningQuestion($normalized)) {
            return [
                'message' => $this->blendGreeting($message, 'A little, yes. I keep track of the conversation, the pages people visit, and the website content I have already learned, so I can get more helpful over time without pretending to be human.'),
                'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                    'What have you learned about this site?',
                    'What can you help with?',
                ], $profile),
                'actions' => $this->buildDefaultActions($pageContext, $profile),
                'confidence' => 0.97,
            ];
        }

        return null;
    }

    private function buildContentCountResponse(string $message, int $siteId, array $knowledgeIndex): ?array
    {
        if (!$this->isCountIntent($message)) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $matchedSection = $this->matchSectionFromQuestion($message, $sections);

        if ($matchedSection === null) {
            if (preg_match('/\b(how many|number of|count of)\s+(pages?|entries?|items?|content)\b/i', $message)) {
                $count = (int)($knowledgeIndex['summary']['liveEntryCount'] ?? Entry::find()
                    ->siteId($siteId ?: null)
                    ->status('live')
                    ->count());

                return [
                    'message' => $this->blendGreeting(
                        $message,
                        $count === 1
                        ? 'At the moment, there is 1 live content entry on the website.'
                        : "At the moment, there are {$count} live content entries on the website."
                    ),
                    'suggestions' => [
                        'What kinds of content are on the website?',
                        'Summarize this page',
                        'Help me find something',
                    ],
                    'actions' => [],
                    'confidence' => 0.95,
                ];
            }

            return null;
        }

        $count = Entry::find()
            ->siteId($siteId ?: null)
            ->section($matchedSection['handle'])
            ->status('live')
            ->count();

        $label = $matchedSection['label'];
        $messageText = match (true) {
            $count === 0 => "At the moment, I cannot see any {$label} published on the website.",
            $count === 1 => "At the moment, there is 1 {$label} on the website.",
            default => "At the moment, there are {$count} {$label} on the website.",
        };

        return [
            'message' => $this->blendGreeting($message, $messageText),
            'suggestions' => [
                "Show me the {$matchedSection['name']} section",
                'Summarize this page',
                'Help me find something else',
            ],
            'actions' => [],
            'confidence' => 0.97,
        ];
    }

    private function buildRecommendationResponse(
        string $message,
        string $normalized,
        int $siteId,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        array $profile,
        array $visitorIntent = []
    ): ?array {
        if (!$this->isRecommendationIntent($normalized)) {
            return null;
        }

        $sectionCatalog = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $entries = $this->searchEntries($message, $siteId, $knowledgeIndex);
        $sectionSuggestion = $this->pickRecommendedSection($message, $sectionCatalog, $visitorIntent);
        $pageSuggestion = $this->pickRecommendedPage($message, $knowledgeIndex, $visitorIntent);

        $primaryTitle = null;
        $primaryUrl = null;
        $primarySummary = '';

        if ($entries !== []) {
            $primaryTitle = trim((string)($entries[0]['title'] ?? ''));
            $primaryUrl = trim((string)($entries[0]['url'] ?? ''));
            $primarySummary = trim((string)($entries[0]['summary'] ?? ''));
        } elseif ($pageSuggestion !== null) {
            $primaryTitle = trim((string)($pageSuggestion['title'] ?? ''));
            $primaryUrl = trim((string)($pageSuggestion['url'] ?? ''));
            $primarySummary = trim((string)($pageSuggestion['summary'] ?? ''));
        } elseif ($sectionSuggestion !== null) {
            $primaryTitle = trim((string)($sectionSuggestion['name'] ?? ''));
        }

        if ($primaryTitle === null || $primaryTitle === '') {
            return null;
        }

        $messageText = 'If you want the best place to start, I would begin with ' . $primaryTitle . '.';
        if ($primarySummary !== '') {
            $messageText .= ' ' . StringHelper::truncate($primarySummary, 180);
        } elseif ($sectionSuggestion !== null && $primaryTitle === trim((string)($sectionSuggestion['name'] ?? ''))) {
            $count = (int)($sectionSuggestion['count'] ?? 0);
            if ($count > 0) {
                $messageText .= " That area has {$count} live item" . ($count === 1 ? '' : 's') . ' and looks like one of the strongest content hubs on the site.';
            }
        }

        $alternatives = [];
        if ($entries !== []) {
            foreach (array_slice($entries, 1, 2) as $entry) {
                $title = trim((string)($entry['title'] ?? ''));
                if ($title !== '' && $title !== $primaryTitle) {
                    $alternatives[] = $title;
                }
            }
        } elseif ($sectionSuggestion !== null) {
            foreach (array_slice($sectionCatalog, 0, 3) as $section) {
                $title = trim((string)($section['name'] ?? ''));
                if ($title !== '' && $title !== $primaryTitle && (int)($section['count'] ?? 0) > 0) {
                    $alternatives[] = $title;
                }
            }
        }

        if ($alternatives !== []) {
            $messageText .= ' After that, I would also keep an eye on ' . $this->humanizeList(array_slice($alternatives, 0, 2)) . '.';
        }

        $intentLead = $this->describeVisitorIntent($visitorIntent);
        if ($intentLead !== '') {
            $messageText .= ' ' . $intentLead;
        }

        $actions = $this->buildDefaultActions($pageContext, $profile);
        if ($primaryUrl !== null && $primaryUrl !== '') {
            array_unshift($actions, [
                'type' => 'navigate',
                'url' => $primaryUrl,
                'label' => 'Open ' . $primaryTitle,
            ]);
            $actions = $this->dedupeActions($actions);
        }

        return [
            'message' => $this->blendGreeting($message, $messageText),
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                $primaryUrl !== null && $primaryUrl !== '' ? 'Open ' . $primaryTitle : 'Tell me more about ' . $primaryTitle,
                'Show me something related',
                'What content is on this site?',
            ], $profile),
            'actions' => $actions,
            'confidence' => 0.9,
        ];
    }

    private function buildSiteDiscoveryResponse(string $message, int $siteId, array $knowledgeIndex): ?array
    {
        if (
            !preg_match('/\b(what (?:kind|type)s? of content|what sections|what pages|what is on this site|what content is on this site|what content do you have)\b/i', $message)
        ) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        if ($sections === []) {
            return null;
        }

        $visibleSections = array_values(array_filter($sections, static fn(array $section): bool => ($section['count'] ?? 0) > 0));
        $names = array_map(static fn(array $section): string => $section['name'], array_slice($visibleSections, 0, 8));

        $messageText = 'From what I can see, this website includes ' . $this->humanizeList($names) . '.';

        if (count($visibleSections) > 8) {
            $messageText .= ' There is more as well, but those are the main content areas I can already see.';
        }

        return [
            'message' => $messageText,
            'suggestions' => [
                'Show me the main sections',
                'How many pages are on the site?',
                'Help me find something',
            ],
            'actions' => [],
            'confidence' => 0.96,
        ];
    }

    private function buildSectionNavigationResponse(string $message, int $siteId, array $knowledgeIndex): ?array
    {
        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $matchedSection = $this->matchSectionFromQuestion($message, $sections);

        if ($matchedSection === null || !$this->isNavigationIntent($message)) {
            return null;
        }

        $entry = Entry::find()
            ->siteId($siteId ?: null)
            ->section($matchedSection['handle'])
            ->status('live')
            ->limit(1)
            ->one();

        if (!$entry || !$entry->getUrl()) {
            return null;
        }

        return [
            'message' => "Absolutely. {$matchedSection['name']} looks like the right place to start.",
            'suggestions' => [
                "Open {$matchedSection['name']}",
                'Show me something related',
                'Summarize this page',
            ],
            'actions' => [
                [
                    'type' => 'navigate',
                    'url' => $entry->getUrl(),
                    'label' => "Open {$matchedSection['name']}",
                ],
            ],
            'confidence' => 0.95,
        ];
    }

    private function buildSectionOverviewResponse(
        string $message,
        int $siteId,
        array $knowledgeIndex,
        array $pageContext,
        array $learningState,
        array $profile
    ): ?array {
        if (!preg_match('/\b(what are your|what is your|tell me about your|show me your|do you have)\b/i', $message)) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $matchedSection = $this->matchSectionFromQuestion($message, $sections);
        if ($matchedSection === null) {
            return null;
        }

        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->section($matchedSection['handle'])
            ->status('live')
            ->limit(3)
            ->all();

        $entryTitles = array_values(array_filter(array_map(
            fn(Entry $entry): string => $this->sanitizeResultTitle((string)$entry->title),
            $entries
        )));

        $messageText = "I can help with that. On this site, {$matchedSection['name']} is one of the main content areas.";
        if ($entryTitles !== []) {
            $messageText .= ' A few examples there are ' . $this->humanizeList($entryTitles) . '.';
        } else {
            $count = (int)($matchedSection['count'] ?? 0);
            if ($count > 0) {
                $messageText .= " I can already see {$count} live item" . ($count === 1 ? '' : 's') . " in that section.";
            }
        }

        $actions = $this->buildDefaultActions($pageContext, $profile);
        $firstEntry = $entries[0] ?? null;
        if ($firstEntry && $firstEntry->getUrl()) {
            array_unshift($actions, [
                'type' => 'navigate',
                'url' => $firstEntry->getUrl(),
                'label' => 'Open ' . ($entryTitles[0] ?? $matchedSection['name']),
            ]);
            $actions = $this->dedupeActions($actions);
        }

        return [
            'message' => $this->blendGreeting($message, $messageText),
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Show me ' . $matchedSection['name'],
                $entryTitles !== [] ? 'Open ' . $entryTitles[0] : 'What is on this page?',
                'Tell me more about ' . $matchedSection['name'],
            ], $profile),
            'actions' => $actions,
            'confidence' => 0.98,
        ];
    }

    private function buildSectionPromptResponse(
        string $message,
        int $siteId,
        array $knowledgeIndex,
        array $pageContext,
        array $learningState,
        array $profile
    ): ?array {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '' || preg_match('/\s/u', $normalized)) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $matchedSection = $this->matchSectionFromQuestion($message, $sections);
        if ($matchedSection === null) {
            return null;
        }

        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->section($matchedSection['handle'])
            ->status('live')
            ->limit(3)
            ->all();
        $entryTitles = $this->extractMeaningfulEntryLabels($entries);
        $count = (int)($matchedSection['count'] ?? 0);
        $messageText = $this->buildNaturalSectionLead($matchedSection, $count);

        if ($entryTitles !== []) {
            $messageText .= ' A couple worth a look are ' . $this->humanizeList(array_slice($entryTitles, 0, 2)) . '.';
        }

        $actions = $this->buildDefaultActions($pageContext, $profile);
        if (($entries[0] ?? null) instanceof Entry && $entries[0]->getUrl()) {
            array_unshift($actions, [
                'type' => 'navigate',
                'url' => $entries[0]->getUrl(),
                'label' => 'Go to ' . ($matchedSection['name']),
            ]);
            $actions = $this->dedupeActions($actions);
        }

        return [
            'message' => $messageText,
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Summary of ' . $matchedSection['name'],
                'Open ' . $matchedSection['name'],
                $entryTitles !== [] ? 'Tell me more about ' . $entryTitles[0] : 'What is on this page?',
            ], $profile),
            'actions' => $actions,
            'confidence' => 0.97,
        ];
    }

    private function buildSectionSummaryResponse(
        string $message,
        int $siteId,
        array $knowledgeIndex,
        array $pageContext,
        array $learningState,
        array $profile
    ): ?array {
        if (!preg_match('/\b(summary of|summarize|overview of)\b/i', $message)) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        $matchedSection = $this->matchSectionFromQuestion($message, $sections);
        if ($matchedSection === null) {
            return null;
        }

        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->section($matchedSection['handle'])
            ->status('live')
            ->limit(3)
            ->all();

        $entryTitles = $this->extractMeaningfulEntryLabels($entries);

        $count = (int)($matchedSection['count'] ?? count($entries));
        $messageText = $this->buildNaturalSectionSummaryLead($matchedSection, $count);
        if ($entryTitles !== []) {
            $messageText .= ' A few that stand out are ' . $this->humanizeList($entryTitles) . '.';
        }

        $actions = $this->buildDefaultActions($pageContext, $profile);
        if (($entries[0] ?? null) instanceof Entry && $entries[0]->getUrl()) {
            array_unshift($actions, [
                'type' => 'navigate',
                'url' => $entries[0]->getUrl(),
                'label' => 'Go to ' . $matchedSection['name'],
            ]);
            $actions = $this->dedupeActions($actions);
        }

        return [
            'message' => $messageText,
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Open ' . $matchedSection['name'],
                $entryTitles !== [] ? 'Tell me more about ' . $entryTitles[0] : 'What is on this page?',
                'Show me something related',
            ], $profile),
            'actions' => $actions,
            'confidence' => 0.97,
        ];
    }

    private function buildNaturalSectionLead(array $matchedSection, int $count): string
    {
        $name = trim((string)($matchedSection['name'] ?? 'this section'));
        $normalized = mb_strtolower($name);

        if (preg_match('/\b(testimonial|review)\b/i', $normalized)) {
            $text = "This section is where the site shows client feedback and testimonials";
        } elseif (preg_match('/\b(service)\b/i', $normalized)) {
            $text = "This section covers the services available on the site";
        } elseif (preg_match('/\b(news|article|blog)\b/i', $normalized)) {
            $text = "This section gathers the site's latest stories and updates";
        } else {
            $text = "{$name} is one of the content areas on the site";
        }

        if ($count > 0) {
            $text .= ", and I can see {$count} live item" . ($count === 1 ? '' : 's');
        }

        return $text . '.';
    }

    private function buildNaturalSectionSummaryLead(array $matchedSection, int $count): string
    {
        $name = trim((string)($matchedSection['name'] ?? 'this section'));
        $normalized = mb_strtolower($name);

        if (preg_match('/\b(testimonial|review)\b/i', $normalized)) {
            $text = "The testimonials section gives a sense of how clients describe their experience";
        } elseif (preg_match('/\b(service)\b/i', $normalized)) {
            $text = "The services section outlines what the site offers";
        } elseif (preg_match('/\b(news|article|blog)\b/i', $normalized)) {
            $text = "The news section highlights current updates and published content";
        } else {
            $text = "{$name} covers {$count} live item" . ($count === 1 ? '' : 's') . " on the site";
        }

        if (!preg_match('/\b' . preg_quote((string)$count, '/') . '\b/', $text) && $count > 0) {
            $text .= ". Right now there are {$count} live item" . ($count === 1 ? '' : 's');
        }

        return rtrim($text, '.') . '.';
    }

    private function extractMeaningfulEntryLabels(array $entries): array
    {
        $labels = [];

        foreach ($entries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $title = $this->sanitizeResultTitle((string)$entry->title);
            if ($title !== '' && !$this->isGenericResultLabel($title)) {
                $labels[] = $title;
                continue;
            }

            $summary = $this->buildEntrySummary($entry);
            $summary = $this->sanitizeSummaryText($summary);
            if ($summary === '') {
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $summary) ?: [];
            $candidate = trim((string)($sentences[0] ?? $summary));
            $candidate = StringHelper::truncate($candidate, 80);

            if ($candidate !== '' && !$this->isGenericResultLabel($candidate)) {
                $labels[] = $candidate;
            }
        }

        $labels = array_values(array_unique(array_filter(array_map('trim', $labels))));

        return array_slice($labels, 0, 3);
    }

    private function buildInterestingResponse(
        string $message,
        int $siteId,
        array $pageContext,
        array $knowledgeIndex,
        array $learningState,
        array $profile
    ): ?array {
        if (!preg_match('/\b(say something interesting|tell me something interesting|something interesting|surprise me)\b/i', $message)) {
            return null;
        }

        $sections = $this->discoverSectionCatalog($siteId, $knowledgeIndex);
        usort($sections, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $topSection = $sections[0] ?? null;

        if ($topSection === null) {
            return null;
        }

        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->section($topSection['handle'])
            ->status('live')
            ->limit(2)
            ->all();

        $entryTitles = array_values(array_filter(array_map(
            fn(Entry $entry): string => $this->sanitizeResultTitle((string)$entry->title),
            $entries
        )));

        $messageText = "One interesting thing here is that {$topSection['name']} looks like a strong part of the site";
        $count = (int)($topSection['count'] ?? 0);
        if ($count > 0) {
            $messageText .= ", with {$count} live item" . ($count === 1 ? '' : 's');
        }
        $messageText .= '.';

        if ($entryTitles !== []) {
            $messageText .= ' A couple worth a look are ' . $this->humanizeList($entryTitles) . '.';
        }

        return [
            'message' => $messageText,
            'suggestions' => $this->buildSmartSuggestions($pageContext, $knowledgeIndex, $learningState, [
                'Open ' . $topSection['name'],
                $entryTitles !== [] ? 'Tell me more about ' . $entryTitles[0] : 'What is on this page?',
                'Show me something useful',
            ], $profile),
            'actions' => $this->buildDefaultActions($pageContext, $profile),
            'confidence' => 0.95,
        ];
    }

    private function buildLearnedNavigationResponse(string $message, string $normalized, array $knowledgeIndex): ?array
    {
        if (!$this->isNavigationIntent($normalized)) {
            return null;
        }

        $bestPage = $this->matchLearnedDestination($message, $knowledgeIndex);
        if ($bestPage === null || empty($bestPage['url'])) {
            return null;
        }

        $title = trim((string)($bestPage['title'] ?? 'that page'));
        $summary = trim((string)($bestPage['summary'] ?? ''));
        $messageText = "I think {$title} is the best place to head next.";

        if ($summary !== '') {
            $messageText .= ' ' . StringHelper::truncate($summary, 180);
        }

        return [
            'message' => $messageText,
            'suggestions' => [
                "Open {$title}",
                'Show me another option',
                'Summarize this page',
            ],
            'actions' => [
                [
                    'type' => 'navigate',
                    'url' => (string)$bestPage['url'],
                    'label' => "Open {$title}",
                ],
            ],
            'confidence' => 0.9,
        ];
    }

    private function pickRecommendedSection(string $message, array $sections, array $visitorIntent = []): ?array
    {
        $visibleSections = array_values(array_filter($sections, static fn(array $section): bool => ((int)($section['count'] ?? 0)) > 0));
        if ($visibleSections === []) {
            return null;
        }

        $normalized = mb_strtolower($message);
        $best = null;
        $bestScore = -INF;

        foreach ($visibleSections as $section) {
            $score = (float)($section['count'] ?? 0);
            foreach ((array)($section['keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && str_contains($normalized, mb_strtolower((string)$keyword))) {
                    $score += 8;
                }
            }

            foreach ((array)($visitorIntent['keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && str_contains(mb_strtolower((string)($section['name'] ?? '')), $keyword)) {
                    $score += 6;
                }
            }

            if (preg_match('/\b(news|latest|updates?)\b/i', $message) && preg_match('/\b(news|articles?|blog)\b/i', (string)($section['name'] ?? ''))) {
                $score += 10;
            }

            if (preg_match('/\b(service|offer|help)\b/i', $message) && preg_match('/\b(service|offer)\b/i', (string)($section['name'] ?? ''))) {
                $score += 10;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $section;
            }
        }

        return $best;
    }

    private function pickRecommendedPage(string $message, array $knowledgeIndex, array $visitorIntent = []): ?array
    {
        $pages = array_values(array_filter((array)($knowledgeIndex['pages'] ?? []), static fn(array $page): bool => !empty($page['url']) && !empty($page['title'])));
        if ($pages === []) {
            return null;
        }

        $normalized = mb_strtolower($message);
        $best = null;
        $bestScore = -INF;

        foreach ($pages as $page) {
            $score = 0.0;
            $title = mb_strtolower(trim((string)($page['title'] ?? '')));
            $summary = mb_strtolower(trim((string)($page['summary'] ?? '')));
            $headings = mb_strtolower(implode(' ', (array)($page['headings'] ?? [])));

            if ($title !== '') {
                $score += $this->scoreKeywordOverlap(preg_split('/\s+/', $this->extractKeywords($message)) ?: [], $title, 2.4);
            }
            if ($summary !== '') {
                $score += $this->scoreKeywordOverlap(preg_split('/\s+/', $this->extractKeywords($message)) ?: [], $summary, 1.6);
            }
            if ($headings !== '') {
                $score += $this->scoreKeywordOverlap(preg_split('/\s+/', $this->extractKeywords($message)) ?: [], $headings, 1.8);
            }

            foreach ((array)($visitorIntent['keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && (str_contains($title, $keyword) || str_contains($summary, $keyword) || str_contains($headings, $keyword))) {
                    $score += 3.5;
                }
            }

            if ($title === 'home' || str_contains($title, 'home')) {
                $score += 1.4;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $page;
            }
        }

        return $best;
    }

    private function inferVisitorIntentProfile(string $message, array $pageContext, array $learningState, array $memory): array
    {
        $keywords = preg_split('/\s+/', $this->extractKeywords($message)) ?: [];
        $keywords = array_values(array_filter(array_map(
            static fn(string $keyword): string => mb_strtolower(trim($keyword)),
            $keywords
        )));

        $recentTopics = array_slice(array_values(array_filter(array_map(
            static fn(mixed $topic): string => mb_strtolower(trim((string)$topic)),
            (array)($learningState['recentTopics'] ?? [])
        ))), -8);

        $interest = mb_strtolower(trim((string)($memory['interest'] ?? '')));
        if ($interest !== '') {
            $keywords = array_merge($keywords, preg_split('/\s+/', $interest) ?: []);
        }

        $pageTitle = mb_strtolower(trim((string)($pageContext['title'] ?? '')));
        if ($pageTitle !== '') {
            $keywords[] = $pageTitle;
        }

        $keywords = array_values(array_unique(array_filter($keywords, static fn(string $keyword): bool => mb_strlen($keyword) > 2)));
        $keywords = array_slice(array_merge($keywords, $recentTopics), 0, 12);

        return [
            'keywords' => $keywords,
            'interest' => $interest,
        ];
    }

    private function describeVisitorIntent(array $visitorIntent): string
    {
        $interest = trim((string)($visitorIntent['interest'] ?? ''));
        if ($interest !== '') {
            return 'That should be a good fit for what you seem interested in around ' . $interest . '.';
        }

        $keywords = array_slice((array)($visitorIntent['keywords'] ?? []), 0, 2);
        if ($keywords !== []) {
            return 'I am leaning that way based on the themes that have come up in this visit, like ' . $this->humanizeList($keywords) . '.';
        }

        return '';
    }

    private function buildCommonQuestionResponse(string $message, array $learningState): ?array
    {
        if (!preg_match('/\b(common questions|what do people ask|what are people asking|popular questions|frequently asked)\b/i', $message)) {
            return null;
        }

        $commonQuestions = $this->getCommonQuestions($learningState);
        if ($commonQuestions === []) {
            return [
                'message' => 'I am still getting to know what people ask most here, but I am ready to help with pages, forms, navigation, and quick site questions.',
                'suggestions' => [
                    'Summarize this page',
                    'What content is on this site?',
                    'Help me find something',
                ],
                'actions' => [],
                'confidence' => 0.9,
            ];
        }

        return [
            'message' => 'A few things come up quite a lot here: ' . $this->humanizeList(array_slice($commonQuestions, 0, 3)) . '.',
            'suggestions' => array_slice($commonQuestions, 0, 3),
            'actions' => [],
            'confidence' => 0.93,
        ];
    }

    private function softenWebsiteAnswer(string $message): string
    {
        $openers = [
            'I think I have something useful for you.',
            'That sounds like a good fit.',
            'I have a nice lead for you.',
        ];

        return $openers[array_rand($openers)] . ' ' . $message;
    }

    private function blendGreeting(string $message, string $answer): string
    {
        if (!$this->isGreeting($message)) {
            return $answer;
        }

        $openers = [
            'Hi. ',
            'Hi there. ',
            'Hey. ',
        ];

        return $openers[array_rand($openers)] . $answer;
    }

    private function isGreeting(string $message): bool
    {
        return (bool)preg_match('/\b(hello|hi|hey|good morning|good afternoon)\b/i', $message);
    }

    private function isPureGreeting(string $message): bool
    {
        if (!$this->isGreeting($message)) {
            return false;
        }

        $trimmed = trim($message, " \t\n\r\0\x0B,.!?");

        return (bool)preg_match('/^(hello|hi|hey|good morning|good afternoon)( there)?$/i', $trimmed);
    }

    private function isCountIntent(string $message): bool
    {
        return (bool)preg_match('/\b(how many|number of|count of)\b/i', $message);
    }

    private function isJokeIntent(string $message): bool
    {
        foreach (['joke', 'make me laugh', 'funny'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isThanksIntent(string $message): bool
    {
        return (bool)preg_match('/\b(thanks|thank you|cheers)\b/i', $message);
    }

    private function isHowAreYouIntent(string $message): bool
    {
        return (bool)preg_match('/\b(how are you|how are things|you okay)\b/i', $message);
    }

    private function isStuckIntent(string $message): bool
    {
        return (bool)preg_match('/\b(stuck|confused|lost|not working|problem|issue|having trouble|need help)\b/i', $message);
    }

    private function isWhoAreYouIntent(string $message): bool
    {
        return (bool)preg_match('/\b(who are you|what are you|tell me about yourself)\b/i', $message);
    }

    private function isNameQuestion(string $message): bool
    {
        return (bool)preg_match('/\b(what(?:\'s| is)? your name|do you have a name|can i call you(?: something)?|what should i call you|your name\??)\b/i', $message);
    }

    private function isLearningQuestion(string $message): bool
    {
        return (bool)preg_match(
            '/\b('
            . 'are you learning'
            . '|are you still learning'
            . '|are you developing'
            . '|are you still developing'
            . '|are you learning and developing'
            . '|do you learn'
            . '|can you learn'
            . '|are you getting smarter'
            . '|are you improving'
            . '|are you growing'
            . '|do you remember'
            . ')\b/i',
            $message
        );
    }

    private function isCapabilityIntent(string $message): bool
    {
        return (bool)preg_match('/\b(what(?:\s+else)?\s+can\s+you\s+do|how\s+can\s+you\s+help|what\s+do\s+you\s+do|what\s+else\s+do\s+you\s+do)\b/i', $message);
    }

    private function isInterestingIntent(string $message): bool
    {
        return (bool)preg_match('/\b(say something interesting|tell me something interesting|something interesting|surprise me)\b/i', $message);
    }

    private function isSectionSummaryIntent(string $message): bool
    {
        return (bool)preg_match('/\b(summary of|summarize|overview of)\b/i', $message);
    }

    private function isRecommendationIntent(string $message): bool
    {
        return (bool)preg_match('/\b(what should i|where should i|what is the best|what should i look at first|what should i start with|where do i begin|what is most important|which page is best|what do you recommend)\b/i', $message);
    }

    private function isProfileResetIntent(string $message): bool
    {
        return (bool)preg_match('/\b(clear|reset|delete|remove|forget)\b.*\b(saved|form|profile|details|info|information|memory)\b|\b(clear|reset)\s+my\s+(saved\s+)?(form\s+)?(profile|details|info|information|memory)\b/i', $message);
    }

    private function normalizeCountLabel(string $label): string
    {
        return match ($label) {
            'article', 'news article', 'news articles' => 'news articles',
            'service' => 'services',
            'job', 'vacancy' => 'jobs',
            'team member' => 'team members',
            'event' => 'events',
            'location' => 'locations',
            'testimonial', 'review' => 'testimonials',
            default => $label,
        };
    }

    private function discoverSectionCatalog(?int $siteId = null, ?array $knowledgeIndex = null): array
    {
        if ($knowledgeIndex !== null && !empty($knowledgeIndex['sections'])) {
            return array_values($knowledgeIndex['sections']);
        }

        $catalog = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $name = trim((string)$section->name);
            $handle = trim((string)$section->handle);

            if ($name === '' || $handle === '') {
                continue;
            }

            $keywords = [
                mb_strtolower($name),
                mb_strtolower($handle),
                mb_strtolower($this->toSingular($name)),
                mb_strtolower($this->toSingular($handle)),
            ];

            $keywords = array_values(array_unique(array_filter(array_map(
                static fn(string $keyword): string => trim(preg_replace('/\s+/', ' ', $keyword) ?? ''),
                $keywords
            ))));

            $catalog[] = [
                'name' => $name,
                'handle' => $handle,
                'label' => $this->normalizeCountLabel(mb_strtolower($name)),
                'keywords' => $keywords,
                'count' => Entry::find()
                    ->siteId($siteId ?: null)
                    ->section($handle)
                    ->status('live')
                    ->count(),
            ];
        }

        return $catalog;
    }

    private function matchSectionFromQuestion(string $message, array $sections): ?array
    {
        $normalized = mb_strtolower($message);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($sections as $section) {
            $score = 0;

            foreach ((array)$section['keywords'] as $keyword) {
                if ($keyword !== '' && str_contains($normalized, $keyword)) {
                    $score = max($score, mb_strlen($keyword));
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $section;
            }
        }

        return $bestMatch;
    }

    private function matchLearnedDestination(string $message, array $knowledgeIndex): ?array
    {
        $keywords = preg_split('/\s+/', $this->extractKeywords($message)) ?: [];
        $keywords = array_values(array_filter($keywords));

        if ($keywords === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ((array)($knowledgeIndex['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }

            $title = (string)($page['title'] ?? '');
            $summary = (string)($page['summary'] ?? '');
            $headings = implode(' ', (array)($page['headings'] ?? []));
            $links = array_filter((array)($page['links'] ?? []), 'is_array');
            $haystack = mb_strtolower(trim($title . ' ' . $summary . ' ' . $headings));
            $score = $this->scoreKeywordOverlap($keywords, $haystack, 2.4);

            foreach ($links as $link) {
                $linkText = mb_strtolower(trim((string)($link['text'] ?? '')));
                if ($linkText === '') {
                    continue;
                }

                $linkScore = $this->scoreKeywordOverlap($keywords, $linkText, 3.2);
                if ($linkScore > $score && !empty($link['url'])) {
                    $score = $linkScore;
                    $page = [
                        'title' => trim((string)($link['text'] ?? $title)),
                        'url' => (string)($link['url'] ?? ''),
                        'summary' => $summary,
                    ];
                }
            }

            if ($score > $bestScore && !empty($page['url'])) {
                $bestScore = $score;
                $best = $page;
            }
        }

        return $bestScore >= 3.0 ? $best : null;
    }

    private function toSingular(string $value): string
    {
        $value = trim($value);

        return match (true) {
            str_ends_with($value, 'ies') => substr($value, 0, -3) . 'y',
            str_ends_with($value, 'sses'),
            str_ends_with($value, 'ses') => substr($value, 0, -2),
            str_ends_with($value, 's') && !str_ends_with($value, 'ss') => substr($value, 0, -1),
            default => $value,
        };
    }

    private function isCurrentPageQuestion(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        if (in_array($normalized, ['summarize', 'summarise', 'summary', 'give me a summary'], true)) {
            return true;
        }

        foreach (['this page', 'current page', 'what is this about'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        if (preg_match('/\b(summarize|summary of|tell me about|what is on)\b.{0,20}\b(this page|current page|page|here)\b/i', $message)) {
            return true;
        }

        return false;
    }

    private function isTimeIntent(string $message): bool
    {
        foreach (['time', 'what time', 'current time'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isDateIntent(string $message): bool
    {
        foreach (['date', 'today', 'what day', 'current date'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isTimeZoneIntent(string $message): bool
    {
        return (bool)preg_match('/\b(time\s*zone|timezone)\b/i', $message);
    }

    private function isLocationUtilityIntent(string $message): bool
    {
        $normalized = trim(mb_strtolower($message));

        return in_array($normalized, [
            'location',
            'my location',
            'current location',
            'where am i',
        ], true);
    }

    private function isWeatherIntent(string $message): bool
    {
        foreach (['weather', 'temperature', 'forecast', 'rain', 'sunny'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isNavigationIntent(string $message): bool
    {
        foreach (['go to', 'open', 'navigate', 'take me', 'visit'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isFormIntent(string $message): bool
    {
        foreach ([
            'form',
            'submit',
            'contact',
            'quote',
            'book',
            'enquire',
            'enquiry',
            'apply',
            'fill this in',
            'fill it in',
            'fill this form',
            'fill the form',
            'help me fill',
        ] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeGeneralKnowledge(string $message): bool
    {
        foreach (['what is', 'who is', 'when did', 'latest', 'news', 'internet', 'weather', 'price'] as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function extractLocation(string $message): ?string
    {
        $cleanMessage = trim($message);
        $cleanMessage = preg_replace('/[?!.,]+$/', '', $cleanMessage) ?? $cleanMessage;

        if ($this->isTimeZoneIntent($cleanMessage)) {
            return null;
        }

        if (preg_match('/\b(?:in|at|for)\s+([a-z0-9][a-z0-9\s,\'.-]{1,80})$/i', $cleanMessage, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B.,");
        }

        if (preg_match('/\b(?:weather|time|date)\s+([a-z0-9][a-z0-9\s,\'.-]{2,80})$/i', $cleanMessage, $matches)) {
            return trim($matches[1], " \t\n\r\0\x0B.,");
        }

        return null;
    }

    private function extractInternetTopic(string $message): ?string
    {
        $cleanMessage = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $cleanMessage = preg_replace('/[?!.,]+$/', '', $cleanMessage) ?? $cleanMessage;

        $patterns = [
            '/^(?:tell me about|what is|who is|what are|who are|explain)\s+(.{2,120})$/i',
            '/^(?:do you know about|can you tell me about)\s+(.{2,120})$/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleanMessage, $matches)) {
                $topic = trim((string)($matches[1] ?? ''));
                if ($topic !== '' && !$this->looksSiteSpecific($topic)) {
                    return $topic;
                }
            }
        }

        return null;
    }

    private function looksSiteSpecific(string $topic): bool
    {
        $normalized = mb_strtolower($topic);

        return (bool)preg_match('/\b(this page|this site|this website|here|contact page|form|service page)\b/i', $normalized);
    }

    private function buildTimeDateResponse(string $message, ?string $location, array $pageContext = []): ?string
    {
        $timezone = $this->getDefaultTimezone($pageContext);
        $resolvedLocation = null;

        if ($location !== null) {
            $geo = $this->fetchGeoData($location);
            if ($geo === null || empty($geo['timezone'])) {
                return null;
            }

            $timezone = (string)$geo['timezone'];
            $resolvedLocation = (string)($geo['name'] ?? $location);
        }

        try {
            $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        } catch (\Throwable) {
            return null;
        }

        $place = $resolvedLocation ? " in {$resolvedLocation}" : '';

        if ($this->isTimeIntent($message) && $this->isDateIntent($message)) {
            return 'Right now' . $place . ', it is ' . $now->format('g:i A') . ' on ' . $now->format('l, j F Y') . '.';
        }

        if ($this->isTimeIntent($message)) {
            return 'Right now' . $place . ', it is ' . $now->format('g:i A') . '.';
        }

        return 'Today' . $place . ' is ' . $now->format('l, j F Y') . '.';
    }

    private function getDefaultTimezone(array $pageContext = []): string
    {
        $visitorTimezone = trim((string)($pageContext['visitorTimezone'] ?? ''));
        if ($visitorTimezone !== '' && in_array($visitorTimezone, timezone_identifiers_list(), true)) {
            return $visitorTimezone;
        }

        return $this->getSystemTimezone();
    }

    private function getSystemTimezone(): string
    {
        $timezone = date_default_timezone_get();

        return is_string($timezone) && trim($timezone) !== '' ? $timezone : 'UTC';
    }

    private function fetchWeather(string $location): ?string
    {
        $geo = $this->fetchGeoData($location);
        if ($geo === null || !isset($geo['latitude'], $geo['longitude'])) {
            return null;
        }

        $query = http_build_query([
            'latitude' => $geo['latitude'],
            'longitude' => $geo['longitude'],
            'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m',
            'timezone' => $geo['timezone'] ?? 'auto',
            'forecast_days' => 1,
        ]);

        $weather = $this->fetchJson('https://api.open-meteo.com/v1/forecast?' . $query);
        if (!is_array($weather) || !is_array($weather['current'] ?? null)) {
            return null;
        }

        $current = $weather['current'];
        $name = (string)($geo['name'] ?? $location);
        $temperature = isset($current['temperature_2m']) ? round((float)$current['temperature_2m']) : null;
        $feelsLike = isset($current['apparent_temperature']) ? round((float)$current['apparent_temperature']) : null;
        $wind = isset($current['wind_speed_10m']) ? round((float)$current['wind_speed_10m']) : null;
        $summary = $this->describeWeatherCode((int)($current['weather_code'] ?? -1));

        $parts = [];
        $parts[] = "The weather in {$name} is {$summary}";
        if ($temperature !== null) {
            $parts[] = "with a temperature of {$temperature} degrees Celsius";
        }
        if ($feelsLike !== null) {
            $parts[] = "and it feels like {$feelsLike} degrees";
        }

        $message = implode(' ', $parts) . '.';
        if ($wind !== null) {
            $message .= " Winds are around {$wind} kilometres per hour.";
        }

        return $message;
    }

    private function fetchTopicSummary(string $topic): ?string
    {
        $encodedTopic = rawurlencode(str_replace(' ', '_', trim($topic)));
        if ($encodedTopic === '') {
            return null;
        }

        $response = $this->fetchJsonWithOptions(
            'https://en.wikipedia.org/api/rest_v1/page/summary/' . $encodedTopic,
            [
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: Interactive-AI/1.0',
                ],
            ]
        );

        if (!is_array($response)) {
            return null;
        }

        $extract = trim((string)($response['extract'] ?? ''));
        if ($extract === '') {
            return null;
        }

        return StringHelper::truncate($extract, 320);
    }

    private function getInternetKnowledgeSummary(string $topic, array $knowledgeStore): ?string
    {
        $signature = $this->normalizeQuestionSignature($topic);
        $topics = is_array($knowledgeStore['topics'] ?? null) ? $knowledgeStore['topics'] : [];
        $cached = is_array($topics[$signature] ?? null) ? $topics[$signature] : null;
        $ttl = max(3600, (int)(App::env('INTERACTIVE_AI_INTERNET_TTL') ?: 604800));

        if (
            $cached !== null &&
            !empty($cached['summary']) &&
            (time() - (int)($cached['fetchedAt'] ?? 0)) < $ttl
        ) {
            return (string)$cached['summary'];
        }

        $summary = $this->fetchTopicSummary($topic);
        if ($summary === null) {
            return is_string($cached['summary'] ?? null) ? (string)$cached['summary'] : null;
        }

        $knowledgeStore = $this->storeInternetKnowledgeTopic($knowledgeStore, $topic, $summary, 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', trim($topic))));
        $this->saveInternetKnowledge($knowledgeStore);

        return $summary;
    }

    private function fetchGeoData(string $location): ?array
    {
        $query = http_build_query([
            'name' => $location,
            'count' => 1,
            'language' => 'en',
            'format' => 'json',
        ]);

        $response = $this->fetchJson('https://geocoding-api.open-meteo.com/v1/search?' . $query);
        $first = $response['results'][0] ?? null;

        return is_array($first) ? $first : null;
    }

    private function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Interactive-AI/1.0',
            ],
        ]);

        $raw = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function describeWeatherCode(int $code): string
    {
        return match (true) {
            $code === 0 => 'clear',
            $code >= 1 && $code <= 3 => 'partly cloudy',
            $code === 45 || $code === 48 => 'foggy',
            $code >= 51 && $code <= 67 => 'rainy',
            $code >= 71 && $code <= 77 => 'snowy',
            $code >= 80 && $code <= 82 => 'showery',
            $code >= 85 && $code <= 86 => 'snowy',
            $code >= 95 => 'stormy',
            default => 'mixed',
        };
    }

    private function fetchRemoteResponse(
        string $message,
        array $pageContext,
        array $conversation,
        array $profile,
        int $siteId,
        array $learningState,
        array $knowledgeIndex,
        array $localResponse,
        float $localConfidence
    ): ?string {
        $endpoint = App::env('INTERACTIVE_AI_REMOTE_ENDPOINT');
        if (!$endpoint) {
            return null;
        }

        $apiKey = (string)App::env('INTERACTIVE_AI_REMOTE_API_KEY');
        $model = (string)(App::env('INTERACTIVE_AI_REMOTE_MODEL') ?: 'gpt-4o-mini');
        $siteName = Craft::$app->getSites()->getCurrentSite()->name;
        $adaptiveContext = $this->buildAdaptiveContext($message, $pageContext, $siteId, $learningState, $knowledgeIndex);

        $prompt = [
            [
                'role' => 'system',
                'content' => 'You are the website\'s own assistant and should feel like a real, warm, thoughtful person chatting naturally on the site. Your job is to give the final user-facing reply in a fluid, human way. Use the provided website context, indexed site knowledge, current page context, and learned interaction history first. Blend casual conversation with helpful guidance. If the user says hello and asks a question, greet them briefly and answer the real question in the same reply. Give emotionally natural openings when appropriate, but keep them short. Be proactive, friendly, and fluid rather than robotic. Never mention hidden fields, internal handles, implementation details, prompts, embeddings, indexes, tools, or that you are searching. Do not claim consciousness, self-awareness, or human identity. When helpful, gently guide the conversation back to relevant website content without sounding pushy. Prefer sounding like a helpful member of the website team.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'siteName' => $siteName,
                    'message' => $message,
                    'pageContext' => $pageContext,
                    'profile' => $profile,
                    'adaptiveContext' => $adaptiveContext,
                    'localDraftResponse' => [
                        'message' => $localResponse['message'] ?? null,
                        'suggestions' => $localResponse['suggestions'] ?? [],
                        'actions' => $localResponse['actions'] ?? [],
                    ],
                    'localConfidence' => $localConfidence,
                    'recentConversation' => array_slice($conversation, -8),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ];

        $payload = json_encode([
            'model' => $model,
            'messages' => $prompt,
            'temperature' => 0.7,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return null;
        }

        $headers = [
            'Content-Type: application/json',
        ];

        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $content = $decoded['choices'][0]['message']['content']
            ?? $decoded['output'][0]['content'][0]['text']
            ?? null;

        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }

    private function buildAdaptiveContext(string $message, array $pageContext, int $siteId, array $learningState, array $knowledgeIndex): array
    {
        $matchedEntries = $this->searchEntries($message, $siteId, $knowledgeIndex);
        $siteSnapshot = $knowledgeIndex['entries'] ?? $this->buildSiteSnapshot($siteId);
        $sectionCatalog = $this->discoverSectionCatalog($siteId, $knowledgeIndex);

        return [
            'currentPage' => [
                'title' => $pageContext['title'] ?? null,
                'url' => $pageContext['url'] ?? null,
                'headings' => array_slice((array)($pageContext['headings'] ?? []), 0, 6),
                'contentExcerpt' => StringHelper::truncate((string)($pageContext['contentExcerpt'] ?? ''), 600),
                'hasForms' => !empty($pageContext['forms']),
            ],
            'siteStructure' => [
                'sections' => $sectionCatalog,
                'liveEntryCount' => $knowledgeIndex['summary']['liveEntryCount'] ?? Entry::find()->siteId($siteId ?: null)->status('live')->count(),
                'sectionCount' => count($sectionCatalog),
            ],
            'matchedEntries' => array_slice($matchedEntries, 0, 4),
            'siteSnapshot' => array_slice($siteSnapshot, 0, 20),
            'learning' => [
                'topTopics' => array_slice((array)($learningState['topicCounts'] ?? []), 0, 10, true),
                'recentTopics' => array_slice((array)($learningState['recentTopics'] ?? []), -8),
                'recentPages' => array_slice((array)($learningState['recentPages'] ?? []), -6),
                'preferredContact' => $learningState['preferredContact'] ?? null,
                'visitorName' => $learningState['visitorName'] ?? null,
                'conversationStyle' => $learningState['conversationStyle'] ?? 'warm',
            ],
        ];
    }

    private function buildSiteSnapshot(int $siteId): array
    {
        $entries = Entry::find()
            ->siteId($siteId ?: null)
            ->status(null)
            ->limit(12)
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->all();

        $snapshot = [];
        foreach ($entries as $entry) {
            $snapshot[] = [
                'title' => $entry->title,
                'url' => $entry->getUrl(),
                'section' => $entry->getSection()?->name,
                'summary' => $this->buildEntrySummary($entry),
            ];
        }

        return $snapshot;
    }

    private function getKnowledgeIndex(int $siteId): array
    {
        $path = $this->getKnowledgeIndexPath($siteId);
        $ttl = max(60, (int)(App::env('INTERACTIVE_AI_INDEX_TTL') ?: 600));

        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded) && (time() - (int)($decoded['generatedAt'] ?? 0)) < $ttl) {
                $sanitized = $this->sanitizeKnowledgeIndex($decoded);
                if ($sanitized !== $decoded) {
                    $this->persistKnowledgeIndex($sanitized, $siteId);
                }

                return $sanitized;
            }
        }

        $index = $this->buildKnowledgeIndex($siteId);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $index;
    }

    private function buildKnowledgeIndex(int $siteId): array
    {
        $sections = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $name = trim((string)$section->name);
            $handle = trim((string)$section->handle);

            if ($name === '' || $handle === '') {
                continue;
            }

            $count = (int)Entry::find()
                ->siteId($siteId ?: null)
                ->section($handle)
                ->status('live')
                ->count();

            $keywords = [
                mb_strtolower($name),
                mb_strtolower($handle),
                mb_strtolower($this->toSingular($name)),
                mb_strtolower($this->toSingular($handle)),
            ];

            $sections[$handle] = [
                'name' => $name,
                'handle' => $handle,
                'label' => $this->normalizeCountLabel(mb_strtolower($name)),
                'keywords' => array_values(array_unique(array_filter($keywords))),
                'count' => $count,
            ];
        }

        $entries = [];
        $allEntries = Entry::find()
            ->siteId($siteId ?: null)
            ->status('live')
            ->limit((int)(App::env('INTERACTIVE_AI_INDEX_LIMIT') ?: 250))
            ->orderBy(['elements.dateUpdated' => SORT_DESC])
            ->all();

        foreach ($allEntries as $entry) {
            $summary = $this->buildEntrySummary($entry);
            $title = $this->sanitizeResultTitle($entry->title);
            $record = [
                'title' => $title,
                'url' => $entry->getUrl(),
                'section' => $entry->getSection()?->name,
                'sectionHandle' => $entry->getSection()?->handle,
                'summary' => $summary,
                'keywords' => $this->entryKeywords($entry, $summary),
            ];

            if (!$this->isUsefulResultRecord($record)) {
                continue;
            }

            $embeddingText = trim($title . ' ' . ($entry->getSection()?->name ?? '') . ' ' . $summary);
            $record['embedding'] = $this->embedText($embeddingText);
            $entries[] = $record;
        }

        return [
            'generatedAt' => time(),
            'siteId' => $siteId,
            'summary' => [
                'liveEntryCount' => count($entries),
            ],
            'sections' => $sections,
            'entries' => $entries,
            'pages' => [],
        ];
    }

    private function learnFromPageContext(array $knowledgeIndex, array $pageContext, int $siteId): array
    {
        $url = trim((string)($pageContext['url'] ?? ''));
        $title = $this->cleanExtractedText((string)($pageContext['title'] ?? ''));
        $description = $this->cleanExtractedText((string)($pageContext['description'] ?? ''));
        $headings = array_values(array_filter(array_map(
            fn(mixed $heading): string => $this->cleanExtractedText((string)$heading),
            (array)($pageContext['headings'] ?? [])
        )));
        $contentExcerpt = $this->cleanExtractedText((string)($pageContext['contentExcerpt'] ?? ''));
        $normalizedLinks = $this->normalizeIndexedLinks((array)($pageContext['links'] ?? []));
        $normalizedForms = $this->normalizeIndexedForms((array)($pageContext['forms'] ?? []));

        if ($url === '' || $title === '') {
            return $knowledgeIndex;
        }

        $pages = is_array($knowledgeIndex['pages'] ?? null) ? $knowledgeIndex['pages'] : [];
        $pageRecord = [
            'title' => $title,
            'url' => $url,
            'section' => 'Frontend page',
            'summary' => StringHelper::truncate($contentExcerpt, 500),
            'headings' => array_slice($headings, 0, 10),
            'links' => $normalizedLinks,
            'forms' => $normalizedForms,
            'keywords' => $this->pageKeywords($pageContext),
            'embedding' => $this->embedText(implode(' ', [
                $title,
                $description,
                implode(' ', $headings),
                implode(' ', array_map(static fn(array $link): string => (string)($link['text'] ?? ''), $normalizedLinks)),
                $contentExcerpt,
            ])),
            'capturedAt' => time(),
        ];

        $updated = false;
        foreach ($pages as $index => $page) {
            if (($page['url'] ?? null) === $url) {
                $pages[$index] = $pageRecord;
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            $pages[] = $pageRecord;
        }

        usort($pages, static fn(array $a, array $b): int => ((int)($b['capturedAt'] ?? 0)) <=> ((int)($a['capturedAt'] ?? 0)));
        $knowledgeIndex['pages'] = array_slice($pages, 0, (int)(App::env('INTERACTIVE_AI_PAGE_INDEX_LIMIT') ?: 150));
        $knowledgeIndex['generatedAt'] = time();

        $this->persistKnowledgeIndex($knowledgeIndex, $siteId);

        return $knowledgeIndex;
    }

    private function persistKnowledgeIndex(array $index, int $siteId): void
    {
        $index = $this->sanitizeKnowledgeIndex($index);
        $path = $this->getKnowledgeIndexPath($siteId);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function searchKnowledgeIndex(string $message, array $knowledgeIndex): array
    {
        $knowledgeIndex = $this->sanitizeKnowledgeIndex($knowledgeIndex);
        $semanticResults = $this->searchKnowledgeIndexSemantically($message, $knowledgeIndex);
        if ($semanticResults !== []) {
            return $semanticResults;
        }

        $keywords = preg_split('/\s+/', $this->extractKeywords($message)) ?: [];
        $keywords = array_values(array_filter($keywords));

        if ($keywords === []) {
            return [];
        }

        $scored = [];
        $pool = array_merge(
            (array)($knowledgeIndex['entries'] ?? []),
            (array)($knowledgeIndex['pages'] ?? [])
        );

        foreach ($pool as $entry) {
            if (!$this->isUsefulResultRecord($entry)) {
                continue;
            }

            $title = mb_strtolower((string)($entry['title'] ?? ''));
            $section = mb_strtolower((string)($entry['section'] ?? ''));
            $summary = mb_strtolower((string)($entry['summary'] ?? ''));
            $headings = mb_strtolower(implode(' ', (array)($entry['headings'] ?? [])));
            $links = mb_strtolower(implode(' ', array_map(static fn(array $link): string => (string)($link['text'] ?? ''), array_filter((array)($entry['links'] ?? []), 'is_array'))));
            $forms = mb_strtolower(implode(' ', $this->flattenIndexedFormFields((array)($entry['forms'] ?? []))));
            $entryKeywords = mb_strtolower(implode(' ', (array)($entry['keywords'] ?? [])));

            $score = 0.0;
            $score += $this->scoreKeywordOverlap($keywords, $title, 3.2);
            $score += $this->scoreKeywordOverlap($keywords, $headings, 2.4);
            $score += $this->scoreKeywordOverlap($keywords, $links, 2.1);
            $score += $this->scoreKeywordOverlap($keywords, $summary, 1.6);
            $score += $this->scoreKeywordOverlap($keywords, $section, 1.4);
            $score += $this->scoreKeywordOverlap($keywords, $forms, 1.2);
            $score += $this->scoreKeywordOverlap($keywords, $entryKeywords, 1.0);

            similar_text($this->normalizeQuestionSignature($message), $this->normalizeQuestionSignature($title . ' ' . $summary), $phraseSimilarity);
            $score += $phraseSimilarity / 18;

            if ($score > 0) {
                $entry['score'] = $score;
                $scored[] = $entry;
            }
        }

        usort($scored, static fn(array $a, array $b): int => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return array_map(static function(array $entry): array {
            unset($entry['score'], $entry['keywords'], $entry['sectionHandle'], $entry['capturedAt'], $entry['forms'], $entry['headings'], $entry['links']);
            return $entry;
        }, array_slice($scored, 0, 5));
    }

    private function searchKnowledgeIndexSemantically(string $message, array $knowledgeIndex): array
    {
        $knowledgeIndex = $this->sanitizeKnowledgeIndex($knowledgeIndex);
        $queryVector = $this->embedText($message);
        if ($queryVector === null) {
            return [];
        }

        $pool = array_merge(
            (array)($knowledgeIndex['entries'] ?? []),
            (array)($knowledgeIndex['pages'] ?? [])
        );

        $scored = [];
        foreach ($pool as $entry) {
            if (!$this->isUsefulResultRecord($entry)) {
                continue;
            }

            $vector = $entry['embedding'] ?? null;
            if (!is_array($vector) || $vector === []) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $vector);
            if ($score <= 0.35) {
                continue;
            }

            $entry['score'] = $score;
            $scored[] = $entry;
        }

        usort($scored, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']));

        return array_map(static function(array $entry): array {
            unset(
                $entry['score'],
                $entry['keywords'],
                $entry['sectionHandle'],
                $entry['capturedAt'],
                $entry['forms'],
                $entry['headings'],
                $entry['embedding']
            );
            return $entry;
        }, array_slice($scored, 0, 5));
    }

    private function sanitizeKnowledgeIndex(array $knowledgeIndex): array
    {
        $knowledgeIndex['entries'] = $this->sanitizeKnowledgeRecords((array)($knowledgeIndex['entries'] ?? []));
        $knowledgeIndex['pages'] = $this->sanitizeKnowledgeRecords((array)($knowledgeIndex['pages'] ?? []), true);
        $knowledgeIndex['summary']['liveEntryCount'] = count((array)($knowledgeIndex['entries'] ?? []));

        return $knowledgeIndex;
    }

    private function sanitizeKnowledgeRecords(array $records, bool $allowGenericTitles = false): array
    {
        $sanitized = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $record['title'] = $this->sanitizeResultTitle((string)($record['title'] ?? ''));
            $record['summary'] = $this->sanitizeSummaryText((string)($record['summary'] ?? ''));

            if ($allowGenericTitles && trim((string)($record['title'] ?? '')) === '') {
                $record['title'] = trim((string)($record['url'] ?? ''));
            }

            if (!$this->isUsefulResultRecord($record, $allowGenericTitles)) {
                continue;
            }

            $sanitized[] = $record;
        }

        return $sanitized;
    }

    private function entryKeywords(Entry $entry, string $summary): array
    {
        $text = mb_strtolower(trim($entry->title . ' ' . ($entry->getSection()?->name ?? '') . ' ' . $summary));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $parts = preg_split('/\s+/', $text) ?: [];

        return array_values(array_unique(array_filter($parts, static fn(string $part): bool => mb_strlen($part) > 2)));
    }

    private function pageKeywords(array $pageContext): array
    {
        $text = mb_strtolower(trim(implode(' ', [
            $this->cleanExtractedText((string)($pageContext['title'] ?? '')),
            $this->cleanExtractedText((string)($pageContext['description'] ?? '')),
            implode(' ', array_map(
                fn(mixed $heading): string => $this->cleanExtractedText((string)$heading),
                (array)($pageContext['headings'] ?? [])
            )),
            $this->cleanExtractedText((string)($pageContext['contentExcerpt'] ?? '')),
        ])));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $parts = preg_split('/\s+/', $text) ?: [];

        return array_values(array_unique(array_filter($parts, static fn(string $part): bool => mb_strlen($part) > 2)));
    }

    private function normalizeIndexedForms(array $forms): array
    {
        $normalized = [];

        foreach (array_slice($forms, 0, 6) as $form) {
            if (!is_array($form)) {
                continue;
            }

            $fields = [];
            foreach (array_slice((array)($form['fields'] ?? []), 0, 8) as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $label = $this->cleanExtractedText((string)($field['label'] ?? $field['placeholder'] ?? $field['name'] ?? ''));
                if ($label !== '') {
                    $fields[] = $label;
                }
            }

            $normalized[] = [
                'id' => (string)($form['id'] ?? ''),
                'fields' => $fields,
            ];
        }

        return $normalized;
    }

    private function normalizeIndexedLinks(array $links): array
    {
        $normalized = [];

        foreach (array_slice($links, 0, 20) as $link) {
            if (!is_array($link)) {
                continue;
            }

            $text = $this->cleanExtractedText((string)($link['text'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));

            if ($text === '' || $url === '') {
                continue;
            }

            $normalized[] = [
                'text' => $text,
                'url' => $url,
            ];
        }

        return $normalized;
    }

    private function flattenIndexedFormFields(array $forms): array
    {
        $fields = [];

        foreach ($forms as $form) {
            if (!is_array($form)) {
                continue;
            }

            foreach ((array)($form['fields'] ?? []) as $field) {
                $label = trim((string)$field);
                if ($label !== '') {
                    $fields[] = $label;
                }
            }
        }

        return $fields;
    }

    private function loadLearningState(): array
    {
        $path = $this->getLearningStatePath();
        if (!is_file($path)) {
            return [
                'topicCounts' => [],
                'questionCounts' => [],
                'recentTopics' => [],
                'recentPages' => [],
                'recentQuestions' => [],
            ];
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [
            'topicCounts' => [],
            'questionCounts' => [],
            'recentTopics' => [],
            'recentPages' => [],
            'recentQuestions' => [],
        ];
    }

    private function saveLearningState(array $state): void
    {
        $path = $this->getLearningStatePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function rememberInteraction(array $state, string $message, array $pageContext, array $memory): array
    {
        $state['topicCounts'] = is_array($state['topicCounts'] ?? null) ? $state['topicCounts'] : [];
        $state['questionCounts'] = is_array($state['questionCounts'] ?? null) ? $state['questionCounts'] : [];
        $state['recentTopics'] = is_array($state['recentTopics'] ?? null) ? $state['recentTopics'] : [];
        $state['recentPages'] = is_array($state['recentPages'] ?? null) ? $state['recentPages'] : [];
        $state['recentQuestions'] = is_array($state['recentQuestions'] ?? null) ? $state['recentQuestions'] : [];

        $keywords = preg_split('/\s+/', $this->extractKeywords($message)) ?: [];
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $state['topicCounts'][$keyword] = (int)($state['topicCounts'][$keyword] ?? 0) + 1;
            $state['recentTopics'][] = $keyword;
        }

        $state['recentTopics'] = array_slice(array_values(array_unique($state['recentTopics'])), -20);

        $pageTitle = trim((string)($pageContext['title'] ?? ''));
        if ($pageTitle !== '') {
            $state['recentPages'][] = $pageTitle;
            $state['recentPages'] = array_slice(array_values(array_unique($state['recentPages'])), -20);
        }

        $cleanQuestion = trim(StringHelper::truncate(preg_replace('/\s+/', ' ', $message) ?? $message, 180));
        if ($cleanQuestion !== '') {
            $state['recentQuestions'][] = $cleanQuestion;
            $state['recentQuestions'] = array_slice($state['recentQuestions'], -30);

            $signature = $this->normalizeQuestionSignature($cleanQuestion);
            if ($signature !== '') {
                if (!isset($state['questionCounts'][$signature])) {
                    $state['questionCounts'][$signature] = [
                        'question' => $cleanQuestion,
                        'count' => 0,
                    ];
                }

                $state['questionCounts'][$signature]['question'] = $cleanQuestion;
                $state['questionCounts'][$signature]['count'] = (int)($state['questionCounts'][$signature]['count'] ?? 0) + 1;
            }
        }

        if (!empty($memory['preferredContact'])) {
            $state['preferredContact'] = $memory['preferredContact'];
        }

        if (!empty($memory['visitorName'])) {
            $state['visitorName'] = $memory['visitorName'];
        }

        $state['conversationStyle'] = $this->inferConversationStyle($message);

        arsort($state['topicCounts']);
        uasort($state['questionCounts'], static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $state['questionCounts'] = array_slice($state['questionCounts'], 0, 60, true);

        return $state;
    }

    private function loadInternetKnowledge(): array
    {
        $path = $this->getInternetKnowledgePath();
        if (!is_file($path)) {
            return [
                'generatedAt' => time(),
                'topics' => [],
            ];
        }

        $decoded = json_decode((string)file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [
            'generatedAt' => time(),
            'topics' => [],
        ];
    }

    private function saveInternetKnowledge(array $knowledge): void
    {
        $path = $this->getInternetKnowledgePath();
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($knowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function rememberInternetTopic(array $knowledge, string $message): array
    {
        $topic = $this->extractInternetTopic($message);
        if ($topic === null) {
            return $knowledge;
        }

        $signature = $this->normalizeQuestionSignature($topic);
        if ($signature === '') {
            return $knowledge;
        }

        $topics = is_array($knowledge['topics'] ?? null) ? $knowledge['topics'] : [];
        if (!isset($topics[$signature])) {
            $topics[$signature] = [
                'topic' => $topic,
                'count' => 0,
                'summary' => null,
                'source' => null,
                'fetchedAt' => null,
                'updatedAt' => time(),
            ];
        }

        $topics[$signature]['topic'] = $topic;
        $topics[$signature]['count'] = (int)($topics[$signature]['count'] ?? 0) + 1;
        $topics[$signature]['updatedAt'] = time();

        uasort($topics, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $knowledge['topics'] = array_slice($topics, 0, 120, true);
        $knowledge['generatedAt'] = time();

        return $knowledge;
    }

    private function storeInternetKnowledgeTopic(array $knowledge, string $topic, string $summary, ?string $source = null): array
    {
        $signature = $this->normalizeQuestionSignature($topic);
        if ($signature === '') {
            return $knowledge;
        }

        $topics = is_array($knowledge['topics'] ?? null) ? $knowledge['topics'] : [];
        $existing = is_array($topics[$signature] ?? null) ? $topics[$signature] : [];

        $topics[$signature] = [
            'topic' => $topic,
            'count' => (int)($existing['count'] ?? 0),
            'summary' => StringHelper::truncate(trim($summary), 500),
            'source' => $source,
            'fetchedAt' => time(),
            'updatedAt' => time(),
        ];

        uasort($topics, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $knowledge['topics'] = array_slice($topics, 0, 120, true);
        $knowledge['generatedAt'] = time();

        return $knowledge;
    }

    private function getInternetKnowledgePath(): string
    {
        return Craft::getAlias('@storage/interactive-ai/internet-knowledge.json');
    }

    private function getCommonQuestions(array $learningState): array
    {
        $questionCounts = is_array($learningState['questionCounts'] ?? null) ? $learningState['questionCounts'] : [];
        if ($questionCounts === []) {
            return [];
        }

        uasort($questionCounts, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $top = array_slice($questionCounts, 0, 5);

        return array_values(array_filter(array_map(static function(array $item): string {
            return trim((string)($item['question'] ?? ''));
        }, $top)));
    }

    private function getCommonInternetTopics(array $internetKnowledge): array
    {
        $topics = is_array($internetKnowledge['topics'] ?? null) ? $internetKnowledge['topics'] : [];
        if ($topics === []) {
            return [];
        }

        uasort($topics, static fn(array $a, array $b): int => ((int)($b['count'] ?? 0)) <=> ((int)($a['count'] ?? 0)));
        $top = array_slice($topics, 0, 5);

        return array_values(array_filter(array_map(static function(array $item): string {
            $topic = trim((string)($item['topic'] ?? ''));
            return $topic !== '' ? 'Tell me about ' . $topic : '';
        }, $top)));
    }

    private function buildLearnedPrompt(array $learningState): string
    {
        $commonQuestions = $this->getCommonQuestions($learningState);
        if ($commonQuestions === []) {
            return '';
        }

        $openers = [
            'A few visitors have been asking about similar things lately.',
            'A couple of topics keep coming up here as well.',
            'People on the site have been curious about similar things too.',
        ];

        return $openers[array_rand($openers)] . ' You could ask me things like "' . $commonQuestions[0] . '" if that helps.';
    }

    private function buildFallbackSuggestions(array $learningState, array $internetKnowledge = []): array
    {
        $suggestions = [
            'What is on this page?',
            'Help me find something',
            'Take me to contact',
        ];

        foreach (array_slice($this->getCommonQuestions($learningState), 0, 2) as $question) {
            $suggestions[] = $question;
        }

        foreach (array_slice($this->getCommonInternetTopics($internetKnowledge), 0, 1) as $topicPrompt) {
            $suggestions[] = $topicPrompt;
        }

        return array_values(array_slice(array_unique(array_filter($suggestions)), 0, 4));
    }

    private function normalizeQuestionSignature(string $message): string
    {
        $message = mb_strtolower($message);
        $message = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $message) ?? '';
        $parts = preg_split('/\s+/', trim($message)) ?: [];
        $parts = array_values(array_filter($parts, static function(string $part): bool {
            return mb_strlen($part) > 2 && !in_array($part, [
                'the', 'and', 'for', 'with', 'this', 'that', 'from', 'have', 'what', 'when', 'where', 'would',
                'could', 'there', 'here', 'please', 'about', 'into', 'your',
            ], true);
        }));

        return implode(' ', array_slice($parts, 0, 12));
    }

    private function scoreKeywordOverlap(array $keywords, string $haystack, float $weight = 1.0): float
    {
        if ($haystack === '' || $keywords === []) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            if (str_contains($haystack, $keyword)) {
                $score += mb_strlen($keyword) * $weight;
                continue;
            }

            similar_text($keyword, $haystack, $similarity);
            if ($similarity >= 45) {
                $score += ($similarity / 100) * $weight;
            }
        }

        return $score;
    }

    private function inferConversationStyle(string $message): string
    {
        $normalized = mb_strtolower($message);

        return match (true) {
            str_contains($normalized, 'joke'),
            str_contains($normalized, 'funny'),
            str_contains($normalized, 'haha') => 'playful',
            str_contains($normalized, 'please'),
            str_contains($normalized, 'thank') => 'warm',
            default => 'natural',
        };
    }

    private function getLearningStatePath(): string
    {
        return Craft::getAlias('@storage/interactive-ai/learning-state.json');
    }

    private function getKnowledgeIndexPath(int $siteId): string
    {
        return Craft::getAlias("@storage/interactive-ai/knowledge-index-{$siteId}.json");
    }

    private function embedText(string $text): ?array
    {
        $endpoint = (string)App::env('INTERACTIVE_AI_EMBEDDINGS_ENDPOINT');
        if ($endpoint === '') {
            return null;
        }

        $apiKey = (string)App::env('INTERACTIVE_AI_EMBEDDINGS_API_KEY');
        $model = (string)(App::env('INTERACTIVE_AI_EMBEDDINGS_MODEL') ?: 'text-embedding-3-small');
        $input = trim(StringHelper::truncate($text, 4000));

        if ($input === '') {
            return null;
        }

        $payload = json_encode([
            'model' => $model,
            'input' => $input,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return null;
        }

        $headers = [
            'Content-Type: application/json',
        ];

        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $response = $this->fetchJsonWithOptions($endpoint, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $vector = $response['data'][0]['embedding'] ?? null;

        return is_array($vector) ? array_map('floatval', $vector) : null;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $av = (float)$a[$i];
            $bv = (float)$b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        if ($normA <= 0 || $normB <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private function fetchJsonWithOptions(string $url, array $extraCurlOptions = []): ?array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $baseOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Interactive-AI/1.0',
            ],
        ];

        curl_setopt_array($ch, $baseOptions + $extraCurlOptions);

        $raw = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || $statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
