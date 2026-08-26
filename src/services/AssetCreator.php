<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\models\AssetTarget;
use arifje\craftimagecreator\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\ElementHelper;
use RuntimeException;
use Throwable;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

final class AssetCreator extends Component
{
    /** @param array<string, mixed> $target */
    public function resolveTarget(array $target): AssetTarget
    {
        $fieldUid = trim((string)($target['fieldUid'] ?? ''));
        $elementId = filter_var($target['elementId'] ?? null, FILTER_VALIDATE_INT);
        $siteId = filter_var($target['siteId'] ?? null, FILTER_VALIDATE_INT);
        $elementType = trim((string)($target['elementType'] ?? ''));
        if (
            $fieldUid === '' ||
            $elementId === false ||
            $elementId < 1 ||
            $siteId === false ||
            $siteId < 1 ||
            $elementType === '' ||
            !class_exists($elementType) ||
            !is_a($elementType, ElementInterface::class, true)
        ) {
            throw new RuntimeException('Save the element before creating an image for this field.');
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->isAssetFieldEnabled($fieldUid)) {
            throw new ForbiddenHttpException('Image Creator is not enabled for this field.');
        }

        $field = Craft::$app->getFields()->getFieldByUid($fieldUid);
        if (!$field instanceof AssetsField) {
            throw new RuntimeException('The selected Assets field no longer exists.');
        }
        if (!$field->allowUploads) {
            throw new RuntimeException('This Assets field does not allow uploads.');
        }
        if (
            $field->restrictFiles &&
            is_array($field->allowedKinds) &&
            $field->allowedKinds !== [] &&
            !in_array(Asset::KIND_IMAGE, $field->allowedKinds, true)
        ) {
            throw new RuntimeException('This Assets field does not allow images.');
        }

        /** @var ElementInterface|null $owner */
        $owner = Craft::$app->getElements()->getElementById((int)$elementId, $elementType, (int)$siteId);
        $user = Craft::$app->getUser()->getIdentity();
        if (!$owner instanceof ElementInterface || !$user) {
            throw new RuntimeException('The field owner could not be found. Save the element and try again.');
        }

        // ElementInterface::getRootOwner() was added in Craft 4.12; the helper
        // provides the same behavior across the full supported Craft 4 range.
        $rootOwner = ElementHelper::rootElement($owner);
        if (
            $rootOwner::isLocalized() &&
            Craft::$app->getIsMultiSite() &&
            !Craft::$app->getUser()->checkPermission('editSite:' . $rootOwner->getSite()->uid)
        ) {
            throw new ForbiddenHttpException('You are not allowed to edit content for this site.');
        }
        if (!Craft::$app->getElements()->canSave($rootOwner, $user)) {
            throw new ForbiddenHttpException('You are not allowed to edit this element.');
        }

        $layoutField = null;
        foreach ($owner->getFieldLayout()?->getCustomFields() ?? [] as $candidate) {
            if ($candidate instanceof AssetsField && (string)$candidate->uid === $fieldUid) {
                $layoutField = $candidate;
                break;
            }
        }
        if (!$layoutField instanceof AssetsField) {
            throw new RuntimeException('The selected Assets field is not available for this element.');
        }
        // Craft 5 can override a field handle per layout. Keep using the layout's
        // field instance so dynamic paths resolve with the effective field data.
        $field = $layoutField;

        $folderId = $field->resolveDynamicPathToFolderId($owner);
        $folder = Craft::$app->getAssets()->getFolderById($folderId);
        if (!$folder || !$folder->id || !$folder->volumeId) {
            throw new RuntimeException('The field upload location could not be resolved.');
        }
        $volume = $folder->getVolume();
        if (!Craft::$app->getUser()->checkPermission('saveAssets:' . $volume->uid)) {
            throw new ForbiddenHttpException('You are not allowed to save Assets in this volume.');
        }

        $selectionCondition = $field->getSelectionCondition();
        if ($selectionCondition instanceof ElementCondition) {
            $selectionCondition->referenceElement = $owner;
        }

        return new AssetTarget($owner, $field, $folder, $selectionCondition);
    }

    /** @param array<string, mixed> $target */
    public function save(
        string $token,
        int $userId,
        array $target,
        string $filename,
    ): Asset {
        $resolvedTarget = $this->resolveTarget($target);
        $mutex = Craft::$app->getMutex();
        $lockName = 'craft-image-creator:save:' . hash('sha256', $token);
        if (!$mutex->acquire($lockName, 1)) {
            throw new RuntimeException('This generated image is already being saved. Wait a moment and try again.');
        }

        try {
            $result = Plugin::getInstance()->generatedResults->get(
                $token,
                $userId,
                $resolvedTarget->binding()
            );

            $targetFilename = $this->prepareFilename($filename, $result['extension']);
            $assetTempPath = $result['path'] . '.asset-' . bin2hex(random_bytes(12));
            if (!copy($result['path'], $assetTempPath)) {
                throw new RuntimeException('The generated image could not be prepared for Asset storage.');
            }
            $folder = $resolvedTarget->folder;
            $assetFilename = $targetFilename;
            if ($resolvedTarget->selectionCondition !== null) {
                $folder = Craft::$app->getAssets()->getUserTemporaryUploadFolder();
                $assetFilename = bin2hex(random_bytes(16)) . '.' . $result['extension'];
            }

            $asset = new Asset();
            $asset->tempFilePath = $assetTempPath;
            $asset->setFilename($assetFilename);
            if (method_exists($asset, 'setMimeType')) {
                $asset->setMimeType($result['mimeType']);
            }
            $asset->newFolderId = (int)$folder->id;
            $asset->setVolumeId($folder->volumeId);
            $asset->uploaderId = $userId;
            $asset->avoidFilenameConflicts = true;
            $asset->title = AssetsHelper::filename2Title(pathinfo($targetFilename, PATHINFO_FILENAME));
            $asset->setScenario(Asset::SCENARIO_CREATE);

            try {
                if (!$this->saveAsset($asset)) {
                    throw new RuntimeException($this->elementErrors($asset, 'The generated Asset could not be saved.'));
                }

                if ($resolvedTarget->selectionCondition !== null) {
                    if (!$resolvedTarget->selectionCondition->matchElement($asset)) {
                        Craft::$app->getElements()->deleteElement($asset, true);
                        throw new RuntimeException('The generated image does not meet this field’s selection rules.');
                    }

                    $asset->newFilename = $targetFilename;
                    $asset->newFolderId = (int)$resolvedTarget->folder->id;
                    $asset->setScenario(Asset::SCENARIO_MOVE);
                    // Craft's PHPStan extension assumes this second save succeeds,
                    // but volume adapters can still return a validation failure.
                    // @phpstan-ignore-next-line
                    if (!$this->saveAsset($asset)) {
                        throw new RuntimeException($this->elementErrors($asset, 'The generated Asset could not be moved.'));
                    }
                }
            } catch (Throwable $exception) {
                if ($asset->id && Craft::$app->getElements()->getElementById((int)$asset->id, Asset::class)) {
                    Craft::$app->getElements()->deleteElement($asset, true);
                }
                throw $exception;
            } finally {
                if (is_file($assetTempPath)) {
                    @unlink($assetTempPath);
                }
            }

            Plugin::getInstance()->generatedResults->consume($token, $userId);

            return $asset;
        } finally {
            $mutex->release($lockName);
        }
    }

    private function prepareFilename(string $filename, string $extension): string
    {
        if (mb_strlen($filename) > 255) {
            throw new RuntimeException('The generated Asset filename is too long.');
        }

        $baseName = trim(pathinfo($filename, PATHINFO_FILENAME));
        if ($baseName === '') {
            $baseName = 'ai-image-' . date('Ymd-His');
        }

        return AssetsHelper::prepareAssetName($baseName . '.' . $extension);
    }

    private function elementErrors(Asset $asset, string $fallback): string
    {
        $errors = array_filter(array_map(
            static fn(string $error): string => trim($error),
            $asset->getFirstErrors()
        ));

        return $errors !== [] ? implode(' ', $errors) : $fallback;
    }

    private function saveAsset(Asset $asset): bool
    {
        return Craft::$app->getElements()->saveElement($asset);
    }
}
