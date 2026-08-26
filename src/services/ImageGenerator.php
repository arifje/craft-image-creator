<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\providers\GeneratedImage;
use RuntimeException;
use yii\base\Component;

final class ImageGenerator extends Component
{
    public const RATIOS = ['16:9', '9:16', '4:5', '1:1'];
    private const MAX_CONTEXT_LENGTH = 10_000;
    private const MAX_EXTRA_LENGTH = 20_000;
    private const MAX_PROMPT_LENGTH = 50_000;

    /** @param array<string, mixed> $contextValues */
    public function generate(
        string $provider,
        string $ratio,
        array $contextValues,
        string $extraContext,
    ): GeneratedImage {
        $prompt = $this->preparePrompt($provider, $ratio, $contextValues, $extraContext);

        return $this->generateFromPrompt($provider, $ratio, $prompt);
    }

    /** @param array<string, mixed> $contextValues */
    public function preparePrompt(
        string $provider,
        string $ratio,
        array $contextValues,
        string $extraContext,
    ): string {
        $this->validateProviderAndRatio($provider, $ratio);

        return $this->buildPrompt($ratio, $contextValues, $extraContext);
    }

    public function generateFromPrompt(string $provider, string $ratio, string $prompt): GeneratedImage
    {
        $this->validateProviderAndRatio($provider, $ratio);
        if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new RuntimeException('The combined image prompt is invalid.');
        }

        return Plugin::getInstance()->providerRegistry->get($provider)->generate($prompt, $ratio);
    }

    private function validateProviderAndRatio(string $provider, string $ratio): void
    {
        if (!in_array($ratio, self::RATIOS, true)) {
            throw new RuntimeException('The selected image ratio is invalid.');
        }

        $settings = Plugin::getInstance()->getSettings();
        $configuredProviders = array_column($settings->getConfiguredProviderOptions(), 'value');
        if (!in_array($provider, $configuredProviders, true)) {
            throw new RuntimeException('The selected AI image provider is not configured.');
        }
    }

    /** @param array<string, mixed> $contextValues */
    private function buildPrompt(string $ratio, array $contextValues, string $extraContext): string
    {
        $definitions = Plugin::getInstance()->contextFields->getConfiguredDefinitions();
        $allowedKeys = array_fill_keys(array_column($definitions, 'key'), true);
        foreach ($contextValues as $key => $value) {
            if (!is_string($key) || !isset($allowedKeys[$key]) || !is_scalar($value)) {
                throw new RuntimeException('The supplied context fields are invalid.');
            }
        }

        if (mb_strlen($extraContext) > self::MAX_EXTRA_LENGTH) {
            throw new RuntimeException('The extra context is too long.');
        }

        $basePrompt = Plugin::getInstance()->prompts->getPrompt();
        if ($basePrompt === '') {
            throw new RuntimeException('Configure an image prompt before generating images.');
        }

        $parts = [
            $basePrompt,
            "Create one image with a {$ratio} aspect ratio.",
        ];
        if ($definitions !== []) {
            $contextLines = ['Context fields:'];
            foreach ($definitions as $definition) {
                $value = trim((string)($contextValues[$definition['key']] ?? ''));
                if (mb_strlen($value) > self::MAX_CONTEXT_LENGTH) {
                    throw new RuntimeException("The {$definition['label']} context is too long.");
                }
                $contextLines[] = '- ' . $definition['label'] . ': ' . ($value !== '' ? $value : '(empty)');
            }
            $parts[] = implode("\n", $contextLines);
        }

        $extraContext = trim($extraContext);
        if ($extraContext !== '') {
            $parts[] = "Extra context:\n{$extraContext}";
        }

        $prompt = implode("\n\n", $parts);
        if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            throw new RuntimeException('The combined image prompt is too long.');
        }

        return $prompt;
    }
}
