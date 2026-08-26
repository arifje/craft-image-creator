<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\models;

use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionInterface;
use craft\fields\Assets;
use craft\models\VolumeFolder;

final class AssetTarget
{
    public ElementInterface $owner;
    public Assets $field;
    public VolumeFolder $folder;
    public ?ElementConditionInterface $selectionCondition;

    public function __construct(
        ElementInterface $owner,
        Assets $field,
        VolumeFolder $folder,
        ?ElementConditionInterface $selectionCondition,
    ) {
        $this->owner = $owner;
        $this->field = $field;
        $this->folder = $folder;
        $this->selectionCondition = $selectionCondition;
    }

    /** @return array{fieldUid: string, elementId: int, siteId: int, elementType: string} */
    public function binding(): array
    {
        return [
            'fieldUid' => (string)$this->field->uid,
            'elementId' => (int)$this->owner->getId(),
            'siteId' => (int)$this->owner->getSite()->id,
            'elementType' => get_class($this->owner),
        ];
    }
}
