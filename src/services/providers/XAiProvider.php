<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\Plugin;

final class XAiProvider extends AbstractProvider
{
    public function generate(string $prompt, string $ratio): GeneratedImage
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getResolvedApiKey(Settings::PROVIDER_XAI);
        $model = $settings->getResolvedModel(Settings::PROVIDER_XAI);
        if ($apiKey === '' || $model === '') {
            throw new ProviderException('Grok is not configured.');
        }

        // Grok does not currently offer native 4:5 output. Generate 3:4 and let
        // GeneratedResults apply a small, exact center crop before persistence.
        $providerRatio = $ratio === '4:5' ? '3:4' : $ratio;
        $data = $this->postJson(
            'https://api.x.ai/v1/images/generations',
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            [
                // Medium is xAI's default; omitting the redundant field also
                // keeps this payload compatible with image-model aliases.
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'aspect_ratio' => $providerRatio,
                'resolution' => '1k',
                'response_format' => 'b64_json',
            ],
            'Grok'
        );

        $encoded = $data['data'][0]['b64_json'] ?? '';
        if (!is_string($encoded) || trim($encoded) === '') {
            throw ProviderException::fromResult('Grok', $data, [$apiKey]);
        }
        $mimeType = $data['data'][0]['mime_type'] ?? 'image/jpeg';

        return new GeneratedImage(
            $this->decodeBase64($encoded, 'Grok'),
            is_string($mimeType) ? $mimeType : 'image/jpeg'
        );
    }
}
