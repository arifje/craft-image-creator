<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\models\AssetTarget;
use arifje\craftimagecreator\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\base\MissingComponentInterface;
use craft\elements\Asset;
use craft\elements\conditions\ElementCondition;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\ElementHelper;
use craft\models\VolumeFolder;
use RuntimeException;
use Throwable;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

final class AssetCreator extends Component
{
    /** @param array<string, mixed> $target */
    public function resolveTarget(array $target, ?User $user = null): AssetTarget
    {
        $user ??= Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User) {
            throw new RuntimeException('A signed-in user is required to create an Asset.');
        }

        $type = array_key_exists('type', $target)
            ? trim((string)$target['type'])
            : 'field';

        return match ($type) {
            'field' => $this->resolveFieldTarget($target, $user),
            'standalone' => $this->resolveStandaloneTarget($target, $user),
            default => throw new RuntimeException('The Asset destination is invalid.'),
        };
    }

    /** @return array<int, array{label: string, value: string}> */
    public function getStandaloneStorageOptions(string $currentValue = ''): array
    {
        $locations = [];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $fsHandle = $volume->getFsHandle();
            if (!$volume->id || !$volume->uid || !$fsHandle) {
                continue;
            }

            $filesystem = Craft::$app->getFs()->getFilesystemByHandle($fsHandle);
            if (!$filesystem || $filesystem instanceof MissingComponentInterface) {
                continue;
            }

            $locations[] = [
                'filesystemHandle' => $fsHandle,
                'filesystemName' => Craft::t('site', (string)$filesystem->name),
                'volumeName' => Craft::t('site', (string)$volume->name),
                'volumeUid' => (string)$volume->uid,
            ];
        }

        $handleCounts = array_count_values(array_column($locations, 'filesystemHandle'));
        $nameCounts = array_count_values(array_column($locations, 'filesystemName'));
        $options = [];
        foreach ($locations as $location) {
            $label = $location['filesystemName'];
            if (($handleCounts[$location['filesystemHandle']] ?? 0) > 1) {
                $label .= ' — ' . $location['volumeName'];
            } elseif (($nameCounts[$location['filesystemName']] ?? 0) > 1) {
                $label .= ' (' . $location['filesystemHandle'] . ')';
            }
            $options[] = [
                'label' => $label,
                'value' => $location['volumeUid'],
            ];
        }
        usort(
            $options,
            static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label'])
        );

        $currentValue = trim($currentValue);
        if (
            $currentValue !== '' &&
            !in_array($currentValue, array_column($options, 'value'), true)
        ) {
            $options[] = [
                'label' => Craft::t('craft-image-creator', 'Unavailable storage — {uid}', [
                    'uid' => $currentValue,
                ]),
                'value' => $currentValue,
            ];
        }

        array_unshift($options, [
            'label' => Craft::t('craft-image-creator', 'Select a filesystem'),
            'value' => '',
        ]);

        return $options;
    }

    /** @param array<string, mixed> $target */
    private function resolveFieldTarget(array $target, User $user): AssetTarget
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
        if (!$owner instanceof ElementInterface) {
            throw new RuntimeException('The field owner could not be found. Save the element and try again.');
        }

        // ElementInterface::getRootOwner() was added in Craft 4.12; the helper
        // provides the same behavior across the full supported Craft 4 range.
        $rootOwner = ElementHelper::rootElement($owner);
        if (
            $rootOwner::isLocalized() &&
            Craft::$app->getIsMultiSite() &&
            !$user->can('editSite:' . $rootOwner->getSite()->uid)
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
        if (!$user->can('saveAssets:' . $volume->uid)) {
            throw new ForbiddenHttpException('You are not allowed to save Assets in this volume.');
        }

        $selectionCondition = $field->getSelectionCondition();
        if ($selectionCondition instanceof ElementCondition) {
            $selectionCondition->referenceElement = $owner;
        }

        if (!$folder->uid) {
            throw new RuntimeException('The field upload location is invalid.');
        }

        return new AssetTarget($folder, $selectionCondition, [
            'type' => 'field',
            'fieldUid' => (string)$field->uid,
            'elementId' => (int)$owner->getId(),
            'siteId' => (int)$owner->getSite()->id,
            'elementType' => get_class($owner),
            'folderUid' => (string)$folder->uid,
            'volumeUid' => (string)$volume->uid,
        ]);
    }

    /** @param array<string, mixed> $target */
    private function resolveStandaloneTarget(array $target, User $user): AssetTarget
    {
        $configuredVolumeUid = Plugin::getInstance()->getSettings()->getStandaloneVolumeUid();
        if ($configuredVolumeUid === '') {
            throw new RuntimeException('Standalone image storage has not been configured.');
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($configuredVolumeUid);
        $fsHandle = $volume?->getFsHandle();
        $filesystem = $fsHandle ? Craft::$app->getFs()->getFilesystemByHandle($fsHandle) : null;
        if (
            !$volume?->id ||
            !$volume->uid ||
            !$fsHandle ||
            !$filesystem ||
            $filesystem instanceof MissingComponentInterface
        ) {
            throw new RuntimeException('The configured standalone storage location is unavailable.');
        }

        $expectedVolumeUid = trim((string)($target['volumeUid'] ?? ''));
        if ($expectedVolumeUid !== '' && $expectedVolumeUid !== $configuredVolumeUid) {
            throw new RuntimeException('The standalone storage location has changed. Generate the image again.');
        }

        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id);
        if (!$folder instanceof VolumeFolder || !$folder->id || !$folder->uid || !$folder->volumeId) {
            throw new RuntimeException('The configured standalone storage location has no Asset volume root.');
        }

        $expectedFolderUid = trim((string)($target['folderUid'] ?? ''));
        if ($expectedFolderUid !== '' && $expectedFolderUid !== (string)$folder->uid) {
            throw new RuntimeException('The standalone storage location has changed. Generate the image again.');
        }
        if (!$user->can('viewAssets:' . $volume->uid) || !$user->can('saveAssets:' . $volume->uid)) {
            throw new ForbiddenHttpException('You are not allowed to use the standalone storage location.');
        }

        return new AssetTarget($folder, null, [
            'type' => 'standalone',
            'folderUid' => (string)$folder->uid,
            'volumeUid' => (string)$volume->uid,
        ]);
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
