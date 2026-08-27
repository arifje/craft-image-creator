<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\jobs;

use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\providers\ProviderException;
use Craft;
use craft\elements\User;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use RuntimeException;
use Throwable;

final class GenerateImage extends BaseJob
{
    public string $token = '';
    public int $userId = 0;
    public string $provider = '';
    public string $ratio = '';
    public string $prompt = '';

    /** @var array<string, mixed> */
    public array $target = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->generationRequests->markRunning($this->token, $this->userId)) {
            // A redelivered job must never repeat a potentially billable provider
            // request. If the previous worker vanished mid-run, fail its request;
            // completed and cancelled requests are left unchanged by fail().
            $plugin->generationRequests->fail(
                $this->token,
                $this->userId,
                'The image generation failed. Try again.'
            );
            return;
        }

        $user = Craft::$app->getUsers()->getUserById($this->userId);
        if (
            !$user instanceof User ||
            $user->getStatus() !== User::STATUS_ACTIVE ||
            !$user->can('accessCp') ||
            !$user->can(Plugin::PERMISSION_USE)
        ) {
            $plugin->generationRequests->fail(
                $this->token,
                $this->userId,
                'You no longer have permission to generate images.'
            );
            return;
        }

        $stored = false;
        try {
            $resolvedTarget = $plugin->assetCreator->resolveTarget($this->target, $user);
            if ($resolvedTarget->binding() !== $this->target) {
                throw new RuntimeException('The image generation destination has changed.');
            }

            $this->setProgress($queue, 0.05);
            $generatedImage = $plugin->imageGenerator->generateFromPrompt(
                $this->provider,
                $this->ratio,
                $this->prompt
            );
            $this->setProgress($queue, 0.8);
            $plugin->generatedResults->store(
                $generatedImage,
                $this->userId,
                $this->provider,
                $this->ratio,
                $resolvedTarget->binding(),
                $this->token
            );
            $stored = true;
            $this->setProgress($queue, 1.0);

            if (!$plugin->generationRequests->complete($this->token, $this->userId)) {
                $this->discardResult();
                return;
            }
        } catch (Throwable $exception) {
            if ($stored) {
                $this->discardResult();
            }

            try {
                $plugin->generationRequests->fail(
                    $this->token,
                    $this->userId,
                    $exception instanceof ProviderException
                        ? $exception->getPublicMessage()
                        : 'The image generation failed. Try again.'
                );
            } catch (Throwable $statusException) {
                Craft::$app->getErrorHandler()->logException($statusException);
            }

            if ($exception instanceof ProviderException) {
                Craft::warning($exception->getMessage(), __METHOD__);
            } else {
                Craft::$app->getErrorHandler()->logException($exception);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('craft-image-creator', 'Generating an AI image');
    }

    private function discardResult(): void
    {
        try {
            Plugin::getInstance()->generatedResults->discardIfExists($this->token, $this->userId);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
        }
    }
}
