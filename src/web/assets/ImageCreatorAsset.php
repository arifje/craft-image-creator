<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\web\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

final class ImageCreatorAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['image-creator.css'];
        $this->js = ['image-creator.js'];

        parent::init();
    }
}
