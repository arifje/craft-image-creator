<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\services\providers\GeneratedImage;
use Craft;
use craft\helpers\FileHelper;
use RuntimeException;
use Throwable;
use yii\base\Component;
use yii\caching\CacheInterface;

final class GeneratedResults extends Component
{
    public const CACHE_DURATION = 900;
    private const CACHE_KEY_PREFIX = 'craft-image-creator:result:';
    private const MAX_DIMENSION = 8_192;
    private const MAX_PIXELS = 20_000_000;
    private const MAX_RESULT_SIZE = 25_000_000;

    /**
     * @param array<string, mixed> $target
     * @return array{token: string, mimeType: string, extension: string, width: int, height: int}
     */
    public function store(
        GeneratedImage $generatedImage,
        int $userId,
        string $provider,
        string $ratio,
        array $target,
        ?string $token = null,
    ): array {
        if ($generatedImage->bytes === '' || strlen($generatedImage->bytes) > self::MAX_RESULT_SIZE) {
            throw new RuntimeException('The generated image has an invalid file size.');
        }

        $token ??= bin2hex(random_bytes(32));
        $this->validateToken($token);

        $this->cleanupExpiredFiles();
        $directory = $this->resultDirectory();
        FileHelper::createDirectory($directory);
        $baseName = bin2hex(random_bytes(24));
        $downloadPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.download';
        if (file_put_contents($downloadPath, $generatedImage->bytes, LOCK_EX) === false) {
            throw new RuntimeException('Could not store the generated image.');
        }

        try {
            [$mimeType, $extension, $width, $height] = $this->validateImage($downloadPath);
            $resultPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;
            if (!rename($downloadPath, $resultPath)) {
                throw new RuntimeException('Could not prepare the generated image.');
            }

            [$width, $height] = $this->enforceRatio($resultPath, $ratio, $width, $height);
            [$mimeType, $extension, $width, $height] = $this->validateImage($resultPath);
            $result = [
                'userId' => $userId,
                'path' => $resultPath,
                'mimeType' => $mimeType,
                'extension' => $extension,
                'width' => $width,
                'height' => $height,
                'provider' => $provider,
                'ratio' => $ratio,
                'target' => $target,
                'createdAt' => time(),
            ];
            if (!$this->cache()->set($this->cacheKey($token), $result, self::CACHE_DURATION)) {
                @unlink($resultPath);
                throw new RuntimeException('Could not store the generated image result.');
            }

            return [
                'token' => $token,
                'mimeType' => $mimeType,
                'extension' => $extension,
                'width' => $width,
                'height' => $height,
            ];
        } catch (Throwable $exception) {
            if (isset($resultPath) && is_file($resultPath)) {
                @unlink($resultPath);
            }
            throw $exception;
        } finally {
            if (is_file($downloadPath)) {
                @unlink($downloadPath);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $target
     * @return array{
     *     userId: int,
     *     path: string,
     *     mimeType: string,
     *     extension: string,
     *     width: int,
     *     height: int,
     *     provider: string,
     *     ratio: string,
     *     target: array<string, mixed>,
     *     createdAt: int
     * }
     */
    public function get(string $token, int $userId, ?array $target = null): array
    {
        $this->validateToken($token);
        $result = $this->cache()->get($this->cacheKey($token));
        if (
            !is_array($result) ||
            (int)($result['userId'] ?? 0) !== $userId ||
            !isset(
                $result['path'],
                $result['mimeType'],
                $result['extension'],
                $result['width'],
                $result['height'],
                $result['provider'],
                $result['ratio'],
                $result['target'],
                $result['createdAt']
            ) ||
            !is_array($result['target']) ||
            (int)$result['createdAt'] < time() - self::CACHE_DURATION ||
            !is_file((string)$result['path']) ||
            ($target !== null && $result['target'] !== $target)
        ) {
            throw new RuntimeException('The generated image has expired or belongs to another destination. Generate it again.');
        }

        [$mimeType, $extension, $width, $height] = $this->validateImage((string)$result['path']);
        if (
            $mimeType !== $result['mimeType'] ||
            $extension !== $result['extension'] ||
            $width !== (int)$result['width'] ||
            $height !== (int)$result['height']
        ) {
            throw new RuntimeException('The generated image result is no longer valid.');
        }

        /** @var array{
         *     userId: int,
         *     path: string,
         *     mimeType: string,
         *     extension: string,
         *     width: int,
         *     height: int,
         *     provider: string,
         *     ratio: string,
         *     target: array<string, mixed>,
         *     createdAt: int
         * } $result
         */
        return $result;
    }

    public function discard(string $token, int $userId): void
    {
        $result = $this->get($token, $userId);
        $this->cache()->delete($this->cacheKey($token));
        if (is_file($result['path'])) {
            @unlink($result['path']);
        }
    }

    public function discardIfExists(string $token, int $userId): void
    {
        $this->validateToken($token);
        $result = $this->cache()->get($this->cacheKey($token));
        if (!is_array($result) || (int)($result['userId'] ?? 0) !== $userId) {
            return;
        }

        $this->cache()->delete($this->cacheKey($token));
        $path = $result['path'] ?? null;
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    public function consume(string $token, int $userId): void
    {
        $this->validateToken($token);
        $result = $this->cache()->get($this->cacheKey($token));
        if (!is_array($result) || (int)($result['userId'] ?? 0) !== $userId) {
            throw new RuntimeException('The generated image has expired. Generate it again.');
        }

        $this->cache()->delete($this->cacheKey($token));
        $path = $result['path'] ?? null;
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{0: string, 1: string, 2: int, 3: int} */
    private function validateImage(string $path): array
    {
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_RESULT_SIZE) {
            throw new RuntimeException('The generated image has an invalid file size.');
        }

        $mimeType = FileHelper::getMimeType($path, null, false) ?: '';
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('The generated result must be a JPEG or PNG image.'),
        };
        $dimensions = @getimagesize($path);
        if (!is_array($dimensions) || (int)$dimensions[0] < 1 || (int)$dimensions[1] < 1) {
            throw new RuntimeException('The generated result is not a valid image.');
        }

        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if (
            $width > self::MAX_DIMENSION ||
            $height > self::MAX_DIMENSION ||
            $width * $height > self::MAX_PIXELS
        ) {
            throw new RuntimeException('The generated image dimensions are too large.');
        }

        return [$mimeType, $extension, $width, $height];
    }

    /** @return array{0: int, 1: int} */
    private function enforceRatio(string $path, string $ratio, int $width, int $height): array
    {
        $parts = array_map('intval', explode(':', $ratio));
        if (count($parts) !== 2 || $parts[0] < 1 || $parts[1] < 1) {
            throw new RuntimeException('The selected image ratio is invalid.');
        }

        $desiredRatio = $parts[0] / $parts[1];
        if (abs(($width / $height) - $desiredRatio) <= 0.01) {
            return [$width, $height];
        }

        if (($width / $height) > $desiredRatio) {
            $targetWidth = max(1, (int)round($height * $desiredRatio));
            $targetHeight = $height;
        } else {
            $targetWidth = $width;
            $targetHeight = max(1, (int)round($width / $desiredRatio));
        }

        try {
            $image = Craft::$app->getImages()->loadImage($path);
            $image->scaleAndCrop($targetWidth, $targetHeight, false, 'center-center');
            if (!$image->saveAs($path, true)) {
                throw new RuntimeException('Could not crop the generated image to the selected ratio.');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Could not crop the generated image to the selected ratio.', 0, $exception);
        }

        return [$targetWidth, $targetHeight];
    }

    private function resultDirectory(): string
    {
        return Craft::$app->getPath()->getTempPath()
            . DIRECTORY_SEPARATOR
            . 'craft-image-creator-results';
    }

    private function cleanupExpiredFiles(): void
    {
        $directory = $this->resultDirectory();
        if (!is_dir($directory)) {
            return;
        }

        $cutoff = time() - self::CACHE_DURATION;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path) && (filemtime($path) ?: 0) < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function validateToken(string $token): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new RuntimeException('The generated image token is invalid.');
        }
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_KEY_PREFIX . hash('sha256', $token);
    }

    private function cache(): CacheInterface
    {
        $cache = Craft::$app->getCache();
        if (!$cache) {
            throw new RuntimeException('Craft cache storage is required for generated image results.');
        }

        return $cache;
    }
}
