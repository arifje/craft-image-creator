<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parameters()->set('parallel', false);

    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // The upstream preset name is historical; these are formatting rules only.
    // Runtime support is exclusively Craft 5/PHP 8.2.
    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
