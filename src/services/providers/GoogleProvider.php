<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\Plugin;

final class GoogleProvider extends AbstractProvider
{
    public function generate(string $prompt, string $ratio): GeneratedImage
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getResolvedApiKey(Settings::PROVIDER_GOOGLE);
        $model = $settings->getResolvedModel(Settings::PROVIDER_GOOGLE);
        if ($apiKey === '' || $model === '') {
            throw new ProviderException('Google Gemini is not configured.');
        }

        $data = $this->postJson(
            'https://generativelanguage.googleapis.com/v1beta/interactions',
            [
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            [
                'model' => $model,
                'input' => [[
                    'type' => 'text',
                    'text' => $prompt,
                ]],
                'response_format' => [
                    'type' => 'image',
                    'mime_type' => 'image/jpeg',
                    'aspect_ratio' => $ratio,
                    'image_size' => '1K',
                ],
            ],
            'Google Gemini'
        );

        foreach ($data['steps'] ?? [] as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? [] as $content) {
                if (!is_array($content) || ($content['type'] ?? '') !== 'image') {
                    continue;
                }
                $encoded = $content['data'] ?? '';
                if (!is_string($encoded) || trim($encoded) === '') {
                    continue;
                }
                $mimeType = $content['mime_type'] ?? 'image/jpeg';

                return new GeneratedImage(
                    $this->decodeBase64($encoded, 'Google Gemini'),
                    is_string($mimeType) ? $mimeType : 'image/jpeg'
                );
            }
        }

        throw ProviderException::fromResult('Google Gemini', $data, [$apiKey]);
    }
}
