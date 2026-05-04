<?php

namespace kdgraphics\interactiveai;

use Craft;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Request;
use craft\web\View;
use kdgraphics\interactiveai\assets\site\InteractiveAiAsset;
use kdgraphics\interactiveai\models\Settings;
use kdgraphics\interactiveai\services\Assistant;
use kdgraphics\interactiveai\services\LlamaCppService;
use kdgraphics\interactiveai\services\NativeSummarizer;
use yii\base\Event;

class InteractiveAi extends Plugin
{
    public static self $plugin;

    public bool $hasCpSettings = true;

    private bool $frontendRegistered = false;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'assistant' => Assistant::class,
            'summarizer' => NativeSummarizer::class,
            'llamaCpp' => LlamaCppService::class,
        ]);

        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_SAVE,
            function(ElementEvent $event): void {
                self::$plugin->getAssistant()->invalidateKnowledgeIndexes();
            }
        );

        Event::on(
            Entry::class,
            Entry::EVENT_AFTER_DELETE,
            function(ElementEvent $event): void {
                self::$plugin->getAssistant()->invalidateKnowledgeIndexes();
            }
        );

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        if ($request->getIsSiteRequest()) {
            Craft::$app->onInit(function() use ($request): void {
                $this->registerFrontend($request);
            });

            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_TEMPLATE,
                function() use ($request): void {
                    $this->registerFrontend($request);
                }
            );

            Event::on(
                View::class,
                View::EVENT_BEGIN_BODY,
                function() use ($request): void {
                    $this->registerFrontend($request);
                }
            );
        }
    }

    public function getAssistant(): Assistant
    {
        /** @var Assistant $assistant */
        $assistant = $this->get('assistant');

        return $assistant;
    }

    public function getSummarizer(): NativeSummarizer
    {
        /** @var NativeSummarizer $summarizer */
        $summarizer = $this->get('summarizer');

        return $summarizer;
    }

    public function getLlamaCpp(): LlamaCppService
    {
        /** @var LlamaCppService $llamaCpp */
        $llamaCpp = $this->get('llamaCpp');

        return $llamaCpp;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('interactive-ai/settings', [
            'settings' => $this->getSettings(),
            'summarizerStatus' => $this->getSummarizer()->getStatus(),
            'llamaCppStatus' => $this->getLlamaCpp()->getStatus(),
            'llamaTestActionUrl' => UrlHelper::actionUrl('interactive-ai/chat/test-llama-cpp'),
        ]);
    }

    public function getAssistantDisplayName(): string
    {
        $settings = $this->getSettings();
        $name = trim((string)($settings->assistantName ?? ''));

        return $name !== '' ? $name : 'Site Assist';
    }

    private function registerFrontend(?Request $request = null): void
    {
        $request ??= Craft::$app->getRequest();
        $view = Craft::$app->getView();
        $assistantName = $this->getAssistantDisplayName();

        if (
            $this->frontendRegistered
            || $request->getIsActionRequest()
            || $request->getIsCpRequest()
            || $view->getTemplateMode() !== View::TEMPLATE_MODE_SITE
        ) {
            return;
        }

        $view->registerAssetBundle(InteractiveAiAsset::class);
        $view->registerJs(
            'window.InteractiveAIConfig = ' . Json::encode([
                'actionUrl' => UrlHelper::actionUrl('interactive-ai/chat/respond'),
                'siteName' => Craft::$app->getSites()->getCurrentSite()->name,
                'productName' => 'Site Assist',
                'assistantName' => $assistantName,
                'welcomeMessage' => App::env('INTERACTIVE_AI_WELCOME_MESSAGE')
                    ?: "Hi, I am {$assistantName}. I can help you explore this website, guide you to pages, and assist with forms.",
                'remoteEnabled' => (bool)App::env('INTERACTIVE_AI_REMOTE_ENDPOINT'),
            ]) . ';',
            View::POS_HEAD
        );

        $this->frontendRegistered = true;
    }
}
