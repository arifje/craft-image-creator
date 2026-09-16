<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\Plugin;
use Craft;
use RuntimeException;
use Throwable;
use yii\caching\CacheInterface;

final class ModelCatalog
{
    public const CACHE_DURATION = 900;

    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const MAX_MODELS = 200;
    private const MAX_GOOGLE_PAGES = 5;
    private const MAX_PAGE_TOKEN_LENGTH = 1_024;

    /** @return string[] */
    public function cachedModelIds(string $provider): array
    {
        $key = $this->resolvedApiKey($provider);
        if ($key === '') {
            return [];
        }

        try {
            $cached = $this->cache()->get(self::cacheKey($provider, $key));
        } catch (Throwable) {
            // A cache outage must not prevent the settings page from loading.
            return [];
        }

        return self::validCachedIds($cached);
    }

    /** @return string[] */
    public function modelIds(string $provider, bool $forceRefresh = false): array
    {
        $key = $this->resolvedApiKey($provider);
        if ($key === '') {
            throw new RuntimeException('Configure the provider API key before refreshing its image models.');
        }

        if (!$forceRefresh && ($cached = $this->cachedModelIds($provider)) !== []) {
            return $cached;
        }

        $ids = match ($provider) {
            Settings::PROVIDER_OPENAI => self::parseOpenAiIds($this->requestJson(
                $provider,
                $key,
                'https://api.openai.com/v1/models'
            )),
            Settings::PROVIDER_XAI => self::parseXAiIds($this->requestJson(
                $provider,
                $key,
                'https://api.x.ai/v1/image-generation-models'
            )),
            Settings::PROVIDER_GOOGLE => $this->googleModelIds($key),
            default => throw new RuntimeException('The selected AI provider is invalid.'),
        };

        if ($ids === []) {
            throw new RuntimeException('The provider returned no compatible image models. The existing selection has not changed.');
        }

        if (!$this->cache()->set(self::cacheKey($provider, $key), $ids, self::CACHE_DURATION)) {
            throw new RuntimeException('The refreshed image models could not be cached. Try again.');
        }

        return $ids;
    }

    /** @param array<string, mixed> $payload
     *  @return string[]
     */
    public static function parseOpenAiIds(array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $ids = [];
        foreach ($data as $model) {
            $id = is_array($model) ? ($model['id'] ?? null) : null;
            if (self::validId($id) && preg_match('/\Agpt-image-([0-9]+)(?:[.-][a-zA-Z0-9._-]+)?\z/', $id, $matches) === 1) {
                // GPT Image 1 uses a different size contract than OpenAiProvider.
                if ((int)$matches[1] >= 2) {
                    $ids[$id] = true;
                }
            }
            if (count($ids) > self::MAX_MODELS) {
                throw new RuntimeException('The provider returned too many image models.');
            }
        }

        return self::sortedIds($ids);
    }

    /** @param array<string, mixed> $payload
     *  @return string[]
     */
    public static function parseXAiIds(array $payload): array
    {
        $models = $payload['models'] ?? null;
        if (!is_array($models)) {
            return [];
        }

        $ids = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }

            foreach ([$model['id'] ?? null, ...self::aliases($model['aliases'] ?? null)] as $id) {
                if (self::validId($id)) {
                    $ids[$id] = true;
                }
                if (count($ids) > self::MAX_MODELS) {
                    throw new RuntimeException('The provider returned too many image models.');
                }
            }
        }

        return self::sortedIds($ids);
    }

    /** @param array<string, mixed> $payload
     *  @return string[]
     */
    public static function parseGoogleIds(array $payload): array
    {
        $models = $payload['models'] ?? null;
        if (!is_array($models)) {
            return [];
        }

        $ids = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $methods = $model['supportedGenerationMethods'] ?? null;
            if (is_array($methods) && !in_array('generateContent', $methods, true)) {
                continue;
            }

            $id = $model['baseModelId'] ?? null;
            if (!self::validId($id)) {
                $name = $model['name'] ?? null;
                $id = is_string($name) && str_starts_with($name, 'models/')
                    ? substr($name, 7)
                    : null;
            }

            if (self::validId($id) && preg_match('/\Agemini-([0-9]+)(?:[._-][a-z0-9._-]+)?-image(?:[-.][a-z0-9._-]+)?\z/', $id, $matches) === 1) {
                // The Interactions image payload is compatible with Gemini 3+.
                if ((int)$matches[1] >= 3) {
                    $ids[$id] = true;
                }
            }
            if (count($ids) > self::MAX_MODELS) {
                throw new RuntimeException('The provider returned too many image models.');
            }
        }

        return self::sortedIds($ids);
    }

    private function resolvedApiKey(string $provider): string
    {
        if (!in_array($provider, [
            Settings::PROVIDER_OPENAI,
            Settings::PROVIDER_XAI,
            Settings::PROVIDER_GOOGLE,
        ], true)) {
            throw new RuntimeException('The selected AI provider is invalid.');
        }

        return Plugin::getInstance()->getSettings()->getResolvedApiKey($provider);
    }

    /** @return string[] */
    private function googleModelIds(string $key): array
    {
        $ids = [];
        $pageToken = '';
        $seenTokens = [];
        for ($page = 0; $page < self::MAX_GOOGLE_PAGES; $page++) {
            $query = ['pageSize' => 100];
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->requestJson(
                Settings::PROVIDER_GOOGLE,
                $key,
                'https://generativelanguage.googleapis.com/v1beta/models',
                $query
            );
            foreach (self::parseGoogleIds($payload) as $id) {
                $ids[$id] = true;
            }
            if (count($ids) > self::MAX_MODELS) {
                throw new RuntimeException('The provider returned too many image models.');
            }

            $next = $payload['nextPageToken'] ?? '';
            if ($next === '') {
                return self::sortedIds($ids);
            }
            if (!is_string($next) || strlen($next) > self::MAX_PAGE_TOKEN_LENGTH || isset($seenTokens[$next])) {
                throw new RuntimeException('The provider returned invalid model pagination.');
            }
            $seenTokens[$next] = true;
            $pageToken = $next;
        }

        throw new RuntimeException('The provider returned too many model pages.');
    }

    /** @param array<string, int|string> $query
     *  @return array<string, mixed>
     */
    private function requestJson(string $provider, string $key, string $url, array $query = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($provider === Settings::PROVIDER_GOOGLE) {
            $headers['x-goog-api-key'] = $key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        try {
            $response = Craft::createGuzzleClient([
                'connect_timeout' => 5,
                'timeout' => 15,
            ])->get($url, [
                'headers' => $headers,
                'query' => $query,
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Could not connect to the provider model catalog. Try again.');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $response->getBody()->close();
            throw new RuntimeException("The provider model catalog request failed (HTTP {$status}).");
        }

        $body = $response->getBody();
        try {
            $contents = '';
            while (!$body->eof()) {
                $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($contents);
                $chunk = $body->read(min(8_192, $remaining));
                if ($chunk === '') {
                    throw new RuntimeException('The provider returned an incomplete model catalog response.');
                }
                $contents .= $chunk;
                if (strlen($contents) > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('The provider model catalog response is too large.');
                }
            }
            $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('The provider returned an invalid model catalog response.');
        } finally {
            $body->close();
        }

        if (!is_array($payload)) {
            throw new RuntimeException('The provider returned an invalid model catalog response.');
        }

        return $payload;
    }

    /** @return string[] */
    private static function aliases(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private static function validId(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 128
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]*\z/', $value) === 1;
    }

    /** @param array<string, bool> $ids
     *  @return string[]
     */
    private static function sortedIds(array $ids): array
    {
        $values = array_keys($ids);
        rsort($values, SORT_STRING);

        return $values;
    }

    /** @return string[] */
    private static function validCachedIds(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > self::MAX_MODELS) {
            return [];
        }

        foreach ($value as $id) {
            if (!self::validId($id)) {
                return [];
            }
        }

        return array_values(array_unique($value));
    }

    private static function cacheKey(string $provider, string $credential): string
    {
        return 'craft-image-creator:models:v1:' . $provider . ':' . hash('sha256', $credential);
    }

    private function cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        if (!$cache) {
            throw new RuntimeException('Craft cache storage is required to refresh image models.');
        }

        return $cache;
    }
}
