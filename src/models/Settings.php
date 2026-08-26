<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\models;

use Craft;
use craft\base\Model;
use craft\fields\Assets;
use craft\helpers\App;

final class Settings extends Model
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_XAI = 'xai';
    public const PROVIDER_GOOGLE = 'google';

    /** @var array<int, mixed> */
    public array $assetFieldUids = [];
    /** @var array<int, mixed> */
    public array $contextFields = ['title'];
    public string $prompt = 'Create a compelling, editorial-quality image based on the supplied context.';
    public string $defaultProvider = self::PROVIDER_OPENAI;

    public string $openAiApiKey = '';
    public string $openAiModel = 'gpt-image-2';
    public string $xAiApiKey = '';
    public string $xAiModel = 'grok-imagine-image-2.0';
    public string $googleApiKey = '';
    public string $googleModel = 'gemini-3.1-flash-image';

    /** @return array<int, mixed> */
    public function defineRules(): array
    {
        return [
            [['assetFieldUids', 'contextFields'], 'safe'],
            [['prompt', 'openAiApiKey', 'openAiModel', 'xAiApiKey', 'xAiModel', 'googleApiKey', 'googleModel'], 'string'],
            [['prompt'], 'string', 'max' => 20_000],
            [['openAiModel', 'xAiModel', 'googleModel'], 'string', 'max' => 128],
            [['defaultProvider'], 'in', 'range' => array_keys(self::providerLabels())],
            [['assetFieldUids'], 'validateAssetFields'],
            [['contextFields'], 'validateContextFields'],
        ];
    }

    public function validateAssetFields(): void
    {
        foreach ($this->assetFieldUids as $uid) {
            $field = is_string($uid) ? Craft::$app->getFields()->getFieldByUid($uid) : null;
            if (!$field instanceof Assets || !$this->fieldAllowsImages($field)) {
                $this->addError('assetFieldUids', 'Image Creator fields must be existing Assets fields that allow images.');
                return;
            }
        }
    }

    public function validateContextFields(): void
    {
        foreach ($this->contextFields as $locator) {
            if (!is_string($locator)) {
                $this->addError('contextFields', 'Context fields are invalid.');
                return;
            }
            if (in_array($locator, ['title', 'slug'], true)) {
                continue;
            }
            if (!str_starts_with($locator, 'field:')) {
                $this->addError('contextFields', 'Context fields are invalid.');
                return;
            }
            if (!Craft::$app->getFields()->getFieldByUid(substr($locator, 6))) {
                $this->addError('contextFields', 'A selected context field no longer exists.');
                return;
            }
        }
    }

    public function isAssetFieldEnabled(string $uid): bool
    {
        return $uid !== '' && in_array($uid, $this->getAssetFieldUids(), true);
    }

    /** @return string[] */
    public function getAssetFieldUids(): array
    {
        return array_values(array_unique(array_filter(
            $this->assetFieldUids,
            static fn($uid): bool => is_string($uid) && $uid !== ''
        )));
    }

    /** @return string[] */
    public function getContextFields(): array
    {
        return array_values(array_unique(array_filter(
            $this->contextFields,
            static fn($locator): bool => is_string($locator) && $locator !== ''
        )));
    }

    public function getResolvedPrompt(): string
    {
        return trim((string)App::parseEnv($this->prompt));
    }

    public function getResolvedApiKey(string $provider): string
    {
        $value = match ($provider) {
            self::PROVIDER_OPENAI => $this->openAiApiKey,
            self::PROVIDER_XAI => $this->xAiApiKey,
            self::PROVIDER_GOOGLE => $this->googleApiKey,
            default => '',
        };

        return trim((string)App::parseEnv($value));
    }

    public function getResolvedModel(string $provider): string
    {
        $value = match ($provider) {
            self::PROVIDER_OPENAI => $this->openAiModel,
            self::PROVIDER_XAI => $this->xAiModel,
            self::PROVIDER_GOOGLE => $this->googleModel,
            default => '',
        };

        return trim((string)App::parseEnv($value));
    }

    /** @return array<int, array{label: string, value: string}> */
    public static function providerOptions(): array
    {
        $options = [];
        foreach (self::providerLabels() as $value => $label) {
            $options[] = ['label' => $label, 'value' => $value];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    public function getConfiguredProviderOptions(): array
    {
        $options = [];
        foreach (self::providerLabels() as $value => $label) {
            if ($this->getResolvedApiKey($value) !== '' && $this->getResolvedModel($value) !== '') {
                $options[] = ['label' => $label, 'value' => $value];
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function providerLabels(): array
    {
        return [
            self::PROVIDER_OPENAI => 'OpenAI',
            self::PROVIDER_XAI => 'Grok (xAI)',
            self::PROVIDER_GOOGLE => 'Google Gemini',
        ];
    }

    private function fieldAllowsImages(Assets $field): bool
    {
        return $field->allowUploads && (
            !$field->restrictFiles ||
            !is_array($field->allowedKinds) ||
            $field->allowedKinds === [] ||
            in_array('image', $field->allowedKinds, true)
        );
    }
}
