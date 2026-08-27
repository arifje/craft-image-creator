<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\Plugin;

final class OpenAiProvider extends AbstractProvider
{
    private const SIZES = [
        '16:9' => '1536x864',
        '9:16' => '864x1536',
        '4:5' => '1024x1280',
        '1:1' => '1024x1024',
    ];

    public function generate(string $prompt, string $ratio): GeneratedImage
    {
        $settings = Plugin::getInstance()->getSettings();
        $apiKey = $settings->getResolvedApiKey(Settings::PROVIDER_OPENAI);
        $model = $settings->getResolvedModel(Settings::PROVIDER_OPENAI);
        if ($apiKey === '' || $model === '') {
            throw new ProviderException('OpenAI is not configured.');
        }

        $data = $this->postJson(
            'https://api.openai.com/v1/images/generations',
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            [
                'model' => $model,
                'prompt' => $prompt,
                'n' => 1,
                'size' => self::SIZES[$ratio] ?? self::SIZES['1:1'],
                'quality' => 'medium',
                'output_format' => 'jpeg',
            ],
            'OpenAI'
        );

        $encoded = $data['data'][0]['b64_json'] ?? '';
        if (!is_string($encoded) || trim($encoded) === '') {
            throw ProviderException::fromResult('OpenAI', $data, [$apiKey]);
        }

        return new GeneratedImage($this->decodeBase64($encoded, 'OpenAI'), 'image/jpeg');
    }
}
