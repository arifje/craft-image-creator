<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use Craft;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

abstract class AbstractProvider implements ProviderInterface
{
    private const MAX_BASE64_LENGTH = 36_000_000;
    private const MAX_ERROR_RESPONSE_LENGTH = 65_536;

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
            $response = $exception->getResponse();
            if ($response === null) {
                throw ProviderException::fromTransport(
                    $providerLabel,
                    $this->isTimeout($exception)
                );
            }

            throw ProviderException::fromHttpResponse(
                $providerLabel,
                $response->getStatusCode(),
                $this->errorPayload($response),
                $this->requestId($response->getHeaders()),
                $this->secrets($headers)
            );
        } catch (GuzzleException $exception) {
            throw ProviderException::fromTransport(
                $providerLabel,
                $this->isTimeout($exception)
            );
        }

        try {
            $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ProviderException("{$providerLabel} returned an invalid response.");
        }
        if (!is_array($data)) {
            throw new ProviderException("{$providerLabel} returned an invalid response.");
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
            throw new ProviderException("{$providerLabel} returned an image with an invalid size.");
        }

        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '') {
            throw new ProviderException("{$providerLabel} returned invalid image data.");
        }

        return $bytes;
    }

    private function isTimeout(GuzzleException $exception): bool
    {
        if (!$exception instanceof ConnectException) {
            return false;
        }

        $context = $exception->getHandlerContext();

        return (int)($context['errno'] ?? 0) === 28;
    }

    private function errorPayload(ResponseInterface $response): mixed
    {
        try {
            $body = $response->getBody();
            $size = $body->getSize();
            if ($size !== null && $size > self::MAX_ERROR_RESPONSE_LENGTH) {
                return null;
            }
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $contents = $body->read(self::MAX_ERROR_RESPONSE_LENGTH + 1);
        } catch (Throwable) {
            return null;
        }

        if (strlen($contents) > self::MAX_ERROR_RESPONSE_LENGTH) {
            return null;
        }

        try {
            return json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Non-JSON provider bodies are never exposed to control-panel users.
            return null;
        }
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function requestId(array $headers): string
    {
        foreach (['x-request-id', 'request-id', 'x-correlation-id'] as $name) {
            foreach ($headers as $headerName => $values) {
                if (strtolower($headerName) === $name && isset($values[0])) {
                    return $values[0];
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $headers
     * @return array<int, string>
     */
    private function secrets(array $headers): array
    {
        $secrets = [];
        foreach ($headers as $name => $value) {
            if (!in_array(strtolower($name), ['authorization', 'x-api-key', 'x-goog-api-key'], true)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                $secrets[] = $value;
            }
            if (preg_match('/\ABearer\s+(.+)\z/i', $value, $matches) === 1) {
                $secrets[] = trim($matches[1]);
            }
        }

        return array_values(array_unique(array_filter($secrets)));
    }
}
