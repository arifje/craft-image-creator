<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use RuntimeException;

final class ProviderException extends RuntimeException
{
    private const MAX_DETAIL_LENGTH = 300;
    private const MAX_PUBLIC_MESSAGE_LENGTH = 480;

    public function getPublicMessage(): string
    {
        return $this->getMessage();
    }

    /** @param array<int, string> $secrets */
    public static function fromHttpResponse(
        string $providerLabel,
        int $statusCode,
        mixed $payload,
        string $requestId = '',
        array $secrets = [],
    ): self {
        $errorCode = self::errorCode($payload, $secrets);
        $descriptor = "HTTP {$statusCode}";
        if ($errorCode !== '') {
            $descriptor .= ", {$errorCode}";
        }

        $summary = match (true) {
            in_array($statusCode, [400, 409, 413, 415, 422], true) =>
                "{$providerLabel} rejected the image request ({$descriptor})",
            in_array($statusCode, [401, 403], true) =>
                "{$providerLabel} authentication or access failed ({$descriptor})",
            $statusCode === 404 =>
                "{$providerLabel} could not find the configured image model or endpoint ({$descriptor})",
            in_array($statusCode, [408, 504], true) =>
                "The request to {$providerLabel} timed out ({$descriptor})",
            $statusCode === 429 =>
                "{$providerLabel} rate limit or quota was reached ({$descriptor})",
            $statusCode >= 500 =>
                "{$providerLabel} is temporarily unavailable ({$descriptor})",
            default => "{$providerLabel} image generation failed ({$descriptor})",
        };

        $requestId = self::identifier($requestId, $secrets);
        $requestIdSuffix = $requestId !== '' ? " Request ID: {$requestId}." : '';
        $detail = self::sanitize(
            self::errorDetail($payload),
            $secrets,
            self::MAX_DETAIL_LENGTH
        );
        if (
            $detail !== '' &&
            in_array($statusCode, [400, 409, 413, 415, 422], true)
        ) {
            $availableLength = self::MAX_PUBLIC_MESSAGE_LENGTH
                - mb_strlen($summary)
                - mb_strlen($requestIdSuffix)
                - 3;
            $detail = rtrim(mb_substr($detail, 0, max(0, $availableLength)), '. ');
            if ($detail !== '') {
                $summary .= ": {$detail}";
            }
        }
        $summary .= '.' . $requestIdSuffix;

        return new self(mb_substr($summary, 0, self::MAX_PUBLIC_MESSAGE_LENGTH));
    }

    /** @param array<int, string> $secrets */
    public static function fromResult(
        string $providerLabel,
        mixed $payload,
        array $secrets = [],
    ): self {
        $summary = "{$providerLabel} returned no generated image";
        $detail = self::sanitize(
            self::resultDetail($payload),
            $secrets,
            self::MAX_DETAIL_LENGTH
        );
        if ($detail !== '') {
            $availableLength = self::MAX_PUBLIC_MESSAGE_LENGTH - mb_strlen($summary) - 3;
            $detail = rtrim(mb_substr($detail, 0, max(0, $availableLength)), '. ');
            if ($detail !== '') {
                return new self("{$summary}: {$detail}.");
            }
        }

        return new self(
            "{$summary}. The provider may have refused the prompt; try adjusting the prompt or context."
        );
    }

    public static function fromTransport(string $providerLabel, bool $timedOut = false): self
    {
        if ($timedOut) {
            return new self("The request to {$providerLabel} timed out. Try again.");
        }

        return new self(
            "Could not connect to {$providerLabel}. Check outbound HTTPS access and try again."
        );
    }

    private static function errorDetail(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $error = $payload['error'] ?? null;
        $candidates = [
            is_array($error) ? ($error['message'] ?? $error['detail'] ?? null) : $error,
            $payload['message'] ?? null,
            $payload['detail'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $message = self::nestedMessage($candidate);
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    private static function resultDetail(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $candidates = [];
        foreach (['error', 'refusal'] as $key) {
            if (array_key_exists($key, $payload)) {
                $candidates[] = $payload[$key];
            }
        }

        $results = $payload['data'] ?? null;
        $firstResult = is_array($results) ? ($results[0] ?? null) : null;
        if (is_array($firstResult)) {
            foreach (['error', 'refusal'] as $key) {
                if (array_key_exists($key, $firstResult)) {
                    $candidates[] = $firstResult[$key];
                }
            }
        }

        foreach ($candidates as $candidate) {
            $message = self::nestedMessage($candidate);
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    private static function nestedMessage(mixed $value, int $depth = 0): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string)$value);
        }
        if (!is_array($value) || $depth >= 3) {
            return '';
        }

        foreach (['message', 'msg', 'detail'] as $key) {
            if (array_key_exists($key, $value)) {
                $message = self::nestedMessage($value[$key], $depth + 1);
                if ($message !== '') {
                    return $message;
                }
            }
        }

        if (!self::isList($value)) {
            return '';
        }

        foreach ($value as $item) {
            $message = self::nestedMessage($item, $depth + 1);
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $_item) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    /** @param array<int, string> $secrets */
    private static function errorCode(mixed $payload, array $secrets): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $error = $payload['error'] ?? null;
        $code = is_array($error) ? ($error['code'] ?? $error['type'] ?? null) : null;
        $code ??= $payload['code'] ?? $payload['type'] ?? null;
        if (!is_string($code) && !is_int($code)) {
            return '';
        }

        $code = self::sanitize((string)$code, $secrets, 80);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,79}\z/', $code) === 1 ? $code : '';
    }

    /** @param array<int, string> $secrets */
    private static function identifier(string $value, array $secrets): string
    {
        $value = self::sanitize($value, $secrets, 100);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/', $value) === 1 ? $value : '';
    }

    /** @param array<int, string> $secrets */
    private static function sanitize(string $value, array $secrets, int $maxLength): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\p{Cf}+/u', '', $value) ?? '';
        $value = preg_replace('/\p{Cc}+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        foreach ($secrets as $secret) {
            $secret = trim($secret);
            if (strlen($secret) >= 4) {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => 'Bearer [redacted]',
            '/\b(?:xai|sk)-[A-Za-z0-9_-]{8,}\b/i' => '[redacted]',
            '/\bAIza[0-9A-Za-z_-]{20,}\b/' => '[redacted]',
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => '[redacted]',
            '/(\b(?:api[-_ ]?key|access[-_ ]?token|authorization)\b\s*[:=]\s*)["\']?[^\s,"\';]+/i' => '$1[redacted]',
            '/([?&](?:api[_-]?key|key|token|access[_-]?token)=)[^&\s]+/i' => '$1[redacted]',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? '';
        }

        return trim(mb_substr($value, 0, $maxLength));
    }
}
