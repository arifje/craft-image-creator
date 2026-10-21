<?php

declare(strict_types=1);

// Dependency-free service regression: fake Craft persistence applies the final
// volume and conflict-resolved filename, then the real save() checks its rules.
namespace ImageCreatorTestDoubles\yii\base {
    class Component
    {
    }
}

namespace ImageCreatorTestDoubles\craft\base {
    interface ElementInterface
    {
    }
}

namespace ImageCreatorTestDoubles\craft\models {
    class VolumeFolder
    {
        public int $id = 1;
        public int $volumeId = 10;
        public string $uid = 'folder';

        public function getVolume(): object
        {
            return (object)['uid' => 'volume'];
        }
    }
}

namespace ImageCreatorTestDoubles\craft\elements\conditions {
    interface ElementConditionInterface
    {
        public function matchElement(\ImageCreatorTestDoubles\craft\elements\Asset $element): bool;
    }
}

namespace ImageCreatorTestDoubles\craft\elements {
    class User
    {
        public function can(string $permission): bool
        {
            return true;
        }
    }

    class Asset
    {
        public const KIND_IMAGE = 'image';
        public const SCENARIO_CREATE = 'create';
        public const SCENARIO_MOVE = 'move';
        public ?int $id = null;
        public string $tempFilePath;
        public string $filename;
        public string $newFilename;
        public int $newFolderId;
        public int $volumeId;
        public int $uploaderId;
        public bool $avoidFilenameConflicts;
        public string $title;
        public string $scenario;

        public function setMimeType(string $value): void
        {
        }

        public function setFilename(string $value): void
        {
            $this->filename = $value;
        }

        public function setVolumeId(int $value): void
        {
            $this->volumeId = $value;
        }

        public function setScenario(string $value): void
        {
            $this->scenario = $value;
        }
    }
}

namespace ImageCreatorTestDoubles\craft\fields {
    class Assets
    {
        public string $uid = 'field';
        public bool $allowUploads = true;
        public bool $restrictFiles = false;
        public static ?\ImageCreatorTestDoubles\craft\elements\conditions\ElementConditionInterface $condition = null;

        public function resolveDynamicPathToFolderId(\ImageCreatorTestDoubles\craft\base\ElementInterface $owner): int
        {
            return 1;
        }

        public function getSelectionCondition(): ?\ImageCreatorTestDoubles\craft\elements\conditions\ElementConditionInterface
        {
            return self::$condition;
        }
    }
}

namespace ImageCreatorTestDoubles\craft\helpers {
    class Assets
    {
        public static function filename2Title(string $value): string
        {
            return $value;
        }

        public static function prepareAssetName(string $value): string
        {
            return $value;
        }
    }
}

namespace ImageCreatorTestDoubles\arifje\craftimagecreator {
    class Plugin
    {
        public static object $instance;

        public static function getInstance(): object
        {
            return self::$instance;
        }
    }
}

namespace ImageCreatorTestDoubles {
    use arifje\craftimagecreator\services\AssetCreator;
    use ImageCreatorTestDoubles\arifje\craftimagecreator\Plugin;
    use ImageCreatorTestDoubles\craft\elements\Asset;
    use ImageCreatorTestDoubles\craft\fields\Assets;
    use ImageCreatorTestDoubles\craft\models\VolumeFolder;
    use RuntimeException;

    class Craft
    {
        public static object $app;
    }

    class TestOwner implements \ImageCreatorTestDoubles\craft\base\ElementInterface
    {
        public function getRootOwner(): self
        {
            return $this;
        }

        public static function isLocalized(): bool
        {
            return false;
        }

        public function getFieldLayout(): object
        {
            return new class() {
                /** @return list<Assets> */
                public function getCustomFields(): array
                {
                    return [new Assets()];
                }
            };
        }

        public function getId(): int
        {
            return 7;
        }

        public function getSite(): object
        {
            return (object)['id' => 1];
        }
    }

    // Runtime aliases keep this dependency-free process isolated. Distinct
    // double names let static analysis inspect the real Craft contracts too.
    /** @param class-string $double */
    $alias = static function(string $double, string $contract): void {
        class_alias($double, $contract);
    };
    foreach ([
        'yii\\base\\Component',
        'craft\\base\\ElementInterface',
        'craft\\models\\VolumeFolder',
        'craft\\elements\\conditions\\ElementConditionInterface',
        'craft\\elements\\User',
        'craft\\elements\\Asset',
        'craft\\fields\\Assets',
        'craft\\helpers\\Assets',
        'arifje\\craftimagecreator\\Plugin',
        'Craft',
    ] as $contract) {
        $alias(__NAMESPACE__ . '\\' . $contract, $contract);
    }

    require dirname(__DIR__) . '/src/models/AssetTarget.php';
    require dirname(__DIR__) . '/src/services/AssetCreator.php';

    $app = new class() {
        /** @var list<Asset> */
        public array $saved = [];
        /** @var list<int|null> */
        public array $deleted = [];
        public string $finalFilename = '';

        /** @return list<Asset> */
        public function savedAssets(): array
        {
            return $this->saved;
        }

        public function getUser(): self
        {
            return $this;
        }
        public function getIdentity(): \ImageCreatorTestDoubles\craft\elements\User
        {
            return new \ImageCreatorTestDoubles\craft\elements\User();
        }
        public function getFields(): self
        {
            return $this;
        }
        public function getFieldByUid(string $uid): Assets
        {
            return new Assets();
        }
        public function getElements(): self
        {
            return $this;
        }
        public function getElementById(int $id, string $type, ?int $siteId = null): object
        {
            return new TestOwner();
        }
        public function canSave(\ImageCreatorTestDoubles\craft\base\ElementInterface $owner, \ImageCreatorTestDoubles\craft\elements\User $user): bool
        {
            return true;
        }
        public function getAssets(): self
        {
            return $this;
        }
        public function getFolderById(int $id): VolumeFolder
        {
            return new VolumeFolder();
        }
        public function getUserTemporaryUploadFolder(): VolumeFolder
        {
            $folder = new VolumeFolder();
            $folder->id = 2;
            $folder->volumeId = 0;
            return $folder;
        }
        public function getMutex(): self
        {
            return $this;
        }
        public function acquire(string $name, int $timeout): bool
        {
            return true;
        }
        public function release(string $name): void
        {
        }
        public function saveElement(Asset $asset): bool
        {
            $asset->id = 42;
            if ($asset->scenario === Asset::SCENARIO_MOVE) {
                $asset->filename = $this->finalFilename ?: $asset->newFilename;
                $asset->volumeId = 10;
            }
            $this->saved[] = clone $asset;
            return true;
        }
        public function deleteElement(Asset $asset, bool $hard): bool
        {
            $this->deleted[] = $asset->id;
            return true;
        }
    };
    Craft::$app = $app;
    $path = tempnam(sys_get_temp_dir(), 'image-creator-test-');
    file_put_contents($path, 'fixture');
    $results = new class($path) {
        public bool $consumed = false;
        public function __construct(public string $path)
        {
        }
        /** @param array<string, mixed> $binding
         * @return array{extension: string, mimeType: string, path: string}
         */
        public function get(string $token, int $userId, array $binding): array
        {
            return ['extension' => 'png', 'mimeType' => 'image/png', 'path' => $this->path];
        }
        public function consume(string $token, int $userId): void
        {
            $this->consumed = true;
        }
    };
    Plugin::$instance = new class($results) {
        public function __construct(public object $generatedResults)
        {
        }
        public function getSettings(): self
        {
            return $this;
        }
        public function isAssetFieldEnabled(string $uid): bool
        {
            return true;
        }
    };
    $target = ['fieldUid' => 'field', 'elementId' => 7, 'siteId' => 1, 'elementType' => TestOwner::class];
    $assert = static function(bool $value, string $message): void {
        if (!$value) {
            throw new RuntimeException($message);
        }
    };

    try {
        foreach ([['editorial.png', true], ['blocked.png', false], ['editorial-1.png', false]] as [$filename, $accepted]) {
            $app->saved = $app->deleted = [];
            $app->finalFilename = $filename;
            $results->consumed = false;
            Assets::$condition = new class() implements \ImageCreatorTestDoubles\craft\elements\conditions\ElementConditionInterface {
                public function matchElement(\ImageCreatorTestDoubles\craft\elements\Asset $element): bool
                {
                    return $element->volumeId === 10 && $element->filename === 'editorial.png';
                }
            };
            try {
                (new AssetCreator())->save('token', 1, $target, $filename === 'blocked.png' ? $filename : 'editorial.png');
                $assert($accepted, 'An invalid final filename was accepted.');
            } catch (RuntimeException $exception) {
                $assert(!$accepted && str_contains($exception->getMessage(), 'selection rules'), $exception->getMessage());
            }
            $assert(count($app->savedAssets()) === 2, 'Rules ran before the final move.');
            $assert($results->consumed === $accepted, 'Rejected preview must remain available for retry.');
            $assert($app->deleted === ($accepted ? [] : [42]), 'Rejected Asset was not deleted.');
            $assert(glob($path . '.asset-*') === [], 'Temporary Asset copy was not removed.');
        }
        echo "Asset save regression checks passed.\n";
    } finally {
        unlink($path);
        foreach (glob($path . '.asset-*') ?: [] as $copy) {
            unlink($copy);
        }
    }
}
