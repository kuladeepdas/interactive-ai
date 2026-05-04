<?php

namespace kdgraphics\interactiveai\models;

use craft\base\Model;

class Settings extends Model
{
    public string $assistantName = 'Site Assist';
    public int $summarySentences = 3;
    public bool $enableLlamaCpp = false;
    public string $llamaCppBinaryPath = '/usr/local/bin/llama-cli';
    public string $llamaCppModelPath = '';
    public int $llamaCppContextSize = 4096;
    public bool $useLlamaForSummaries = true;
    public bool $useLlamaForAnswers = true;
    public bool $useLlamaForAllResponses = false;

    public function rules(): array
    {
        return [
            [['assistantName'], 'string', 'max' => 80],
            [['assistantName'], 'default', 'value' => 'Site Assist'],
            [['summarySentences'], 'integer', 'min' => 2, 'max' => 4],
            [['summarySentences'], 'default', 'value' => 3],
            [['enableLlamaCpp', 'useLlamaForSummaries', 'useLlamaForAnswers', 'useLlamaForAllResponses'], 'boolean'],
            [['llamaCppBinaryPath', 'llamaCppModelPath'], 'string', 'max' => 500],
            [['llamaCppContextSize'], 'integer', 'min' => 1024, 'max' => 32768],
            [['enableLlamaCpp'], 'default', 'value' => false],
            [['llamaCppBinaryPath'], 'default', 'value' => '/usr/local/bin/llama-cli'],
            [['llamaCppContextSize'], 'default', 'value' => 4096],
            [['useLlamaForSummaries', 'useLlamaForAnswers'], 'default', 'value' => true],
            [['useLlamaForAllResponses'], 'default', 'value' => false],
        ];
    }
}
