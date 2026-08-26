<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Craft 4 is the minimum supported API and syntax baseline. Code formatted
    // against this set remains compatible with Craft 5.
    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
