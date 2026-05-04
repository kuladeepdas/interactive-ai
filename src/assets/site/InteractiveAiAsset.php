<?php

namespace kdgraphics\interactiveai\assets\site;

use craft\web\AssetBundle;

class InteractiveAiAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = [
            'interactive-ai.css',
        ];
        $this->js = [
            'interactive-ai.js',
        ];

        parent::init();
    }
}
