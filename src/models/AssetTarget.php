<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\models;

use craft\elements\conditions\ElementConditionInterface;
use craft\models\VolumeFolder;

final class AssetTarget
{
    public VolumeFolder $folder;
    public ?ElementConditionInterface $selectionCondition;

    /** @var array<string, mixed> */
    private array $binding;

    /** @param array<string, mixed> $binding */
    public function __construct(
        VolumeFolder $folder,
        ?ElementConditionInterface $selectionCondition,
        array $binding,
    ) {
        $this->folder = $folder;
        $this->selectionCondition = $selectionCondition;
        $this->binding = $binding;
    }

    /** @return array<string, mixed> */
    public function binding(): array
    {
        return $this->binding;
    }
}
