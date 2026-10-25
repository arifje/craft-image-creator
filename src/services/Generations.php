<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\jobs\GenerateImage;
use arifje\craftimagecreator\Plugin;
use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use RuntimeException;
use Throwable;

/**
 * One AI image request from start to asset: queue the generation, report its
 * status, save the result, or cancel it. The Create with AI modal's endpoints
 * (CreatorController) and the MCP tools share it.
 */
final class Generations extends \yii\base\Component
{
    /**
     * Resolve and authorize the target, build the prompt and queue the job.
     *
     * @param array<string, mixed> $target
     * @param array<string, string> $context
     * @return string The generation token
     */
    public function queue(int $userId, string $provider, string $ratio, array $context, string $extraContext, array $target): string
    {
        $plugin = Plugin::getInstance();
        $token = null;

        try {
            $resolvedTarget = $plugin->assetCreator->resolveTarget($target);
            $prompt = $plugin->imageGenerator->preparePrompt($provider, $ratio, $context, $extraContext);
            $token = $plugin->generationRequests->create($userId);
            $jobId = Queue::push(new GenerateImage([
                'token' => $token,
                'userId' => $userId,
                'provider' => $provider,
                'ratio' => $ratio,
                'prompt' => $prompt,
                'target' => $resolvedTarget->binding(),
            ]), null, null, 300);
            if ($jobId === null) {
                throw new RuntimeException('Could not add the image generation request to the queue.');
            }
        } catch (Throwable $exception) {
            $this->cancel($token, $userId);
            throw $exception;
        }

        return $token;
    }

    /**
     * The request's status for its owner; once complete, the result's size
     * and type. A complete request whose result expired reports failed.
     *
     * @return array{token: string, status: string, error?: string, result?: array{mimeType: string, extension: string, width: int, height: int}}
     */
    public function status(string $token, int $userId): array
    {
        $plugin = Plugin::getInstance();
        $generation = $plugin->generationRequests->get($token, $userId);
        $status = (string)$generation['status'];
        $payload = ['token' => $token, 'status' => $status];

        if ($status === GenerationRequests::STATUS_COMPLETE) {
            try {
                $result = $plugin->generatedResults->get($token, $userId);
                $payload['result'] = [
                    'mimeType' => $result['mimeType'],
                    'extension' => $result['extension'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                ];
            } catch (RuntimeException) {
                $payload['status'] = GenerationRequests::STATUS_FAILED;
                $payload['error'] = 'The image generation request expired. Generate it again.';
            }
        } elseif ($status === GenerationRequests::STATUS_FAILED) {
            $payload['error'] = (string)$generation['error'];
        } elseif ($status === GenerationRequests::STATUS_CANCELLED) {
            $payload['error'] = 'Image generation was cancelled.';
        }

        return $payload;
    }

    /**
     * Save a complete result as an asset at the target it was generated for,
     * then retire the request.
     *
     * @param array<string, mixed> $target
     */
    public function save(string $token, int $userId, array $target, string $filename): Asset
    {
        $plugin = Plugin::getInstance();
        $asset = $plugin->assetCreator->save($token, $userId, $target, $filename);

        try {
            $plugin->generationRequests->consume($token, $userId);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
        }

        return $asset;
    }

    public function cancel(?string $token, int $userId): void
    {
        if ($token === null || $token === '') {
            return;
        }

        try {
            Plugin::getInstance()->generationRequests->cancel($token, $userId);
            Plugin::getInstance()->generatedResults->discardIfExists($token, $userId);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
        }
    }
}
