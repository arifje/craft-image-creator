<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use Craft;
use RuntimeException;
use yii\base\Component;
use yii\caching\CacheInterface;

final class GenerationRequests extends Component
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    private const ACTIVE_CACHE_DURATION = 3_600;
    private const FINISHED_CACHE_DURATION = 900;
    private const CACHE_KEY_PREFIX = 'craft-image-creator:generation:';
    private const MUTEX_KEY_PREFIX = 'craft-image-creator:generation-lock:';
    private const MAX_ERROR_LENGTH = 500;

    public function create(int $userId): string
    {
        if ($userId < 1) {
            throw new RuntimeException('A signed-in user is required to generate an image.');
        }

        $token = bin2hex(random_bytes(32));
        $now = time();
        $this->store($token, [
            'userId' => $userId,
            'status' => self::STATUS_QUEUED,
            'error' => '',
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        return $token;
    }

    /**
     * @return array{
     *     userId: int,
     *     status: string,
     *     error: string,
     *     createdAt: int,
     *     updatedAt: int
     * }
     */
    public function get(string $token, int $userId): array
    {
        $request = $this->find($token);
        if ($request === null || (int)$request['userId'] !== $userId) {
            throw new RuntimeException('The image generation request has expired.');
        }

        return $request;
    }

    public function markRunning(string $token, int $userId): bool
    {
        return $this->synchronized($token, function() use ($token, $userId): bool {
            $request = $this->find($token);
            if ($request === null || (int)$request['userId'] !== $userId) {
                return false;
            }

            if ((string)$request['status'] !== self::STATUS_QUEUED) {
                return false;
            }

            $request['status'] = self::STATUS_RUNNING;
            $request['error'] = '';
            $request['updatedAt'] = time();
            $this->store($token, $request);

            return true;
        });
    }

    public function complete(string $token, int $userId): bool
    {
        return $this->synchronized($token, function() use ($token, $userId): bool {
            $request = $this->find($token);
            if (
                $request === null ||
                (int)$request['userId'] !== $userId ||
                (string)$request['status'] !== self::STATUS_RUNNING
            ) {
                return false;
            }

            $request['status'] = self::STATUS_COMPLETE;
            $request['error'] = '';
            $request['updatedAt'] = time();
            $this->store($token, $request);

            return true;
        });
    }

    public function fail(string $token, int $userId, string $error): bool
    {
        return $this->synchronized($token, function() use ($token, $userId, $error): bool {
            $request = $this->find($token);
            if (
                $request === null ||
                (int)$request['userId'] !== $userId ||
                in_array(
                    (string)$request['status'],
                    [self::STATUS_COMPLETE, self::STATUS_CANCELLED],
                    true
                )
            ) {
                return false;
            }

            $message = trim($error);
            $request['status'] = self::STATUS_FAILED;
            $request['error'] = mb_substr(
                $message !== '' ? $message : 'The image generation failed. Try again.',
                0,
                self::MAX_ERROR_LENGTH
            );
            $request['updatedAt'] = time();
            $this->store($token, $request);

            return true;
        });
    }

    public function cancel(string $token, int $userId): bool
    {
        return $this->synchronized($token, function() use ($token, $userId): bool {
            $request = $this->find($token);
            if ($request === null || (int)$request['userId'] !== $userId) {
                return false;
            }

            $request['status'] = self::STATUS_CANCELLED;
            $request['error'] = '';
            $request['updatedAt'] = time();
            $this->store($token, $request);

            return true;
        });
    }

    public function consume(string $token, int $userId): void
    {
        $this->synchronized($token, function() use ($token, $userId): void {
            $request = $this->find($token);
            if ($request !== null && (int)$request['userId'] === $userId) {
                $this->cache()->delete($this->cacheKey($token));
            }
        });
    }

    /**
     * @return array{
     *     userId: int,
     *     status: string,
     *     error: string,
     *     createdAt: int,
     *     updatedAt: int
     * }|null
     */
    private function find(string $token): ?array
    {
        $this->validateToken($token);
        $request = $this->cache()->get($this->cacheKey($token));
        if (
            !is_array($request) ||
            !isset(
                $request['userId'],
                $request['status'],
                $request['error'],
                $request['createdAt'],
                $request['updatedAt']
            ) ||
            !in_array(
                (string)$request['status'],
                [
                    self::STATUS_QUEUED,
                    self::STATUS_RUNNING,
                    self::STATUS_COMPLETE,
                    self::STATUS_FAILED,
                    self::STATUS_CANCELLED,
                ],
                true
            ) ||
            (int)$request['createdAt'] < time() - self::ACTIVE_CACHE_DURATION
        ) {
            return null;
        }

        /** @var array{
         *     userId: int,
         *     status: string,
         *     error: string,
         *     createdAt: int,
         *     updatedAt: int
         * } $request
         */
        return $request;
    }

    /** @param array<string, mixed> $request */
    private function store(string $token, array $request): void
    {
        $active = in_array(
            $request['status'] ?? null,
            [self::STATUS_QUEUED, self::STATUS_RUNNING],
            true
        );
        $duration = $active ? self::ACTIVE_CACHE_DURATION : self::FINISHED_CACHE_DURATION;
        if (!$this->cache()->set($this->cacheKey($token), $request, $duration)) {
            throw new RuntimeException('Could not update the image generation request.');
        }
    }

    private function validateToken(string $token): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new RuntimeException('The image generation request is invalid.');
        }
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_KEY_PREFIX . hash('sha256', $token);
    }

    private function mutexKey(string $token): string
    {
        return self::MUTEX_KEY_PREFIX . hash('sha256', $token);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function synchronized(string $token, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        $key = $this->mutexKey($token);
        if (!$mutex->acquire($key, 2)) {
            throw new RuntimeException('The image generation request is busy.');
        }

        try {
            return $callback();
        } finally {
            $mutex->release($key);
        }
    }

    private function cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        if (!$cache) {
            throw new RuntimeException('Craft cache storage is required for image generation.');
        }

        return $cache;
    }
}
