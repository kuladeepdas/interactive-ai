<?php

namespace kdgraphics\interactiveai\controllers;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\Controller;
use kdgraphics\interactiveai\InteractiveAi;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class ChatController extends Controller
{
    protected array|bool|int $allowAnonymous = [
        'respond' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function beforeAction($action): bool
    {
        if ($action->id === 'respond') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionRespond(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $body = $this->request->getRawBody();
        $payload = $body ? Json::decode($body) : $this->request->getBodyParams();

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid assistant payload.');
        }

        try {
            return $this->asJson([
                'success' => true,
                ...InteractiveAi::$plugin->getAssistant()->respond($payload),
            ]);
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'message' => 'I hit a temporary issue while preparing a response. Please try again.',
            ]);
        }
    }

    public function actionTestLlamaCpp(): Response
    {
        $this->requireCpRequest();

        $llamaCpp = InteractiveAi::$plugin->getLlamaCpp();
        $status = $llamaCpp->getStatus();
        $wantsJson = $this->request->getAcceptsJson() || $this->request->getIsPost();

        if (!$status['ready']) {
            $payload = [
                'success' => false,
                'status' => $status,
                'message' => $status['message'] ?? 'llama.cpp is not ready yet.',
            ];

            return $wantsJson
                ? $this->asJson($payload)
                : $this->asRaw($this->renderLlamaTestHtml($payload));
        }

        $sampleTitle = 'Sample Article';
        $sampleText = 'Site Assist helps visitors explore a website, understand what they are looking at, and get gentle help with forms. It can summarize pages and articles, point people to useful content, and keep the conversation feeling warm and natural. The assistant also remembers context across pages so guidance can continue after navigation.';
        $draftReply = 'I can help you understand this article and point you to the next useful page.';
        $sampleMessage = 'Can you quickly explain what this page is about?';

        try {
            $summary = $llamaCpp->summarize($sampleText, $sampleTitle, 3);
            $refinedReply = $llamaCpp->refineAnswer($sampleMessage, $draftReply, [
                'title' => $sampleTitle,
                'headings' => ['Website guidance', 'Summaries', 'Helpful next steps'],
            ]);

            $payload = [
                'success' => true,
                'status' => $status,
                'message' => 'llama.cpp responded successfully.',
                'summary' => $summary,
                'refinedReply' => $refinedReply,
                'samples' => [
                    'title' => $sampleTitle,
                    'message' => $sampleMessage,
                    'draftReply' => $draftReply,
                ],
            ];

            return $wantsJson
                ? $this->asJson($payload)
                : $this->asRaw($this->renderLlamaTestHtml($payload));
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), __METHOD__);

            $payload = [
                'success' => false,
                'status' => $status,
                'message' => 'llama.cpp is configured, but the test request failed.',
                'error' => $e->getMessage(),
            ];

            return $wantsJson
                ? $this->asJson($payload)
                : $this->asRaw($this->renderLlamaTestHtml($payload));
        }
    }

    private function renderLlamaTestHtml(array $payload): string
    {
        $success = (bool)($payload['success'] ?? false);
        $message = Html::encode((string)($payload['message'] ?? ''));
        $summary = Html::encode((string)($payload['summary'] ?? ''));
        $refinedReply = Html::encode((string)($payload['refinedReply'] ?? ''));
        $error = Html::encode((string)($payload['error'] ?? ''));
        $status = $payload['status'] ?? [];
        $binaryPath = Html::encode((string)($status['binaryPath'] ?? ''));
        $modelPath = Html::encode((string)($status['modelPath'] ?? ''));
        $heading = $success ? 'llama.cpp test passed' : 'llama.cpp test result';
        $tone = $success ? '#eaf7ef' : '#fff4f4';
        $border = $success ? '#79b58e' : '#df2e38';

        return <<<HTML
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Site Assist llama.cpp test</title>
    <style>
      body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f5f7fa; color: #1f2933; margin: 0; padding: 32px; }
      .wrap { max-width: 860px; margin: 0 auto; background: #fff; border: 1px solid #d9e2ec; border-radius: 14px; padding: 24px; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08); }
      .status { background: {$tone}; border: 1px solid {$border}; border-radius: 10px; padding: 16px; margin-bottom: 20px; }
      h1 { margin: 0 0 12px; font-size: 28px; }
      h2 { margin: 24px 0 10px; font-size: 18px; }
      p, li { line-height: 1.6; }
      code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
      pre { background: #0f172a; color: #e2e8f0; padding: 14px; border-radius: 10px; overflow: auto; white-space: pre-wrap; }
      ul { padding-left: 18px; }
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="status">
        <h1>{$heading}</h1>
        <p>{$message}</p>
      </div>

      <h2>Configured paths</h2>
      <ul>
        <li><strong>Binary:</strong> <code>{$binaryPath}</code></li>
        <li><strong>Model:</strong> <code>{$modelPath}</code></li>
      </ul>
HTML
        . ($summary !== '' ? "<h2>Summary sample</h2><pre>{$summary}</pre>" : '')
        . ($refinedReply !== '' ? "<h2>Refined reply sample</h2><pre>{$refinedReply}</pre>" : '')
        . ($error !== '' ? "<h2>Error</h2><pre>{$error}</pre>" : '')
        . <<<HTML
    </div>
  </body>
</html>
HTML;
    }
}
