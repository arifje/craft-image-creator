<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use Craft;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    private const MAX_BASE64_LENGTH = 36_000_000;

    /** @param array<string, string> $headers
     *  @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    protected function postJson(
        string $url,
        array $headers,
        array $payload,
        string $providerLabel,
    ): array {
        try {
            $response = Craft::createGuzzleClient([
                'connect_timeout' => 10,
                'timeout' => 180,
            ])->post($url, [
                'headers' => ['Accept' => 'application/json'] + $headers,
                'json' => $payload,
            ]);
        } catch (RequestException $exception) {
            $message = $this->responseError($exception) ?: $exception->getMessage();
            Craft::warning("{$providerLabel} image generation failed: {$message}", __METHOD__);
            throw new RuntimeException("{$providerLabel} could not generate the image: {$message}", 0, $exception);
        } catch (GuzzleException $exception) {
            Craft::warning("{$providerLabel} image generation failed: {$exception->getMessage()}", __METHOD__);
            throw new RuntimeException("{$providerLabel} could not generate the image.", 0, $exception);
        }

        try {
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException("{$providerLabel} returned an invalid response.", 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException("{$providerLabel} returned an invalid response.");
        }

        return $data;
    }

    protected function decodeBase64(string $encoded, string $providerLabel): string
    {
        $encoded = trim($encoded);
        if (str_starts_with($encoded, 'data:')) {
            $comma = strpos($encoded, ',');
            $encoded = $comma === false ? '' : substr($encoded, $comma + 1);
        }
        if ($encoded === '' || strlen($encoded) > self::MAX_BASE64_LENGTH) {
            throw new RuntimeException("{$providerLabel} returned an image with an invalid size.");
        }

        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException("{$providerLabel} returned invalid image data.");
        }

        return $bytes;
    }

    private function responseError(RequestException $exception): string
    {
        $response = $exception->getResponse();
        if (!$response) {
            return '';
        }

        try {
            $data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '';
        }
        if (!is_array($data)) {
            return '';
        }

        $message = $data['error']['message'] ?? $data['message'] ?? $data['detail'] ?? '';

        return is_scalar($message) ? trim((string)$message) : '';
    }
}
