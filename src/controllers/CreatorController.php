<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\controllers;

use arifje\craftimagecreator\jobs\GenerateImage;
use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\services\GenerationRequests;
use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use RuntimeException;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

final class CreatorController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePermission('accessCp');
        $this->requirePermission(Plugin::PERMISSION_USE);

        if (in_array($action->id, ['index', 'preview'], true)) {
            if (!$this->request->getIsGet()) {
                throw new MethodNotAllowedHttpException('This endpoint only accepts GET requests.');
            }
        } else {
            $this->requirePostRequest();
            $this->requireAcceptsJson();
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $plugin->registerCpAssets();
        $standaloneAvailable = true;
        $standaloneUnavailableMessage = '';

        try {
            $plugin->assetCreator->resolveTarget(['type' => 'standalone']);
        } catch (ForbiddenHttpException) {
            $standaloneAvailable = false;
            $standaloneUnavailableMessage = Craft::t(
                'craft-image-creator',
                'You do not have permission to view and save Assets in the configured storage location.'
            );
        } catch (RuntimeException) {
            $standaloneAvailable = false;
            $standaloneUnavailableMessage = Craft::t(
                'craft-image-creator',
                'Standalone storage is not configured or is unavailable. Ask an administrator to choose a filesystem in the Image Creator plugin settings.'
            );
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            $standaloneAvailable = false;
            $standaloneUnavailableMessage = Craft::t(
                'craft-image-creator',
                'The configured standalone storage location could not be loaded.'
            );
        }

        return $this->asCpScreen()
            ->title(Craft::t('craft-image-creator', 'Image Creator'))
            ->contentTemplate('craft-image-creator/_creator.twig', [
                'standaloneAvailable' => $standaloneAvailable,
                'standaloneUnavailableMessage' => $standaloneUnavailableMessage,
            ]);
    }

    public function actionGenerate(): Response
    {
        $request = $this->request;
        $target = $this->arrayParam('target');
        $context = $this->contextParam($request->getBodyParam('context', []));
        $provider = trim((string)$request->getRequiredBodyParam('provider'));
        $ratio = trim((string)$request->getRequiredBodyParam('ratio'));
        $extraContext = trim((string)$request->getBodyParam('extraContext', ''));
        $userId = (int)Craft::$app->getUser()->getId();
        $token = null;

        try {
            $resolvedTarget = Plugin::getInstance()->assetCreator->resolveTarget($target);
            $prompt = Plugin::getInstance()->imageGenerator->preparePrompt(
                $provider,
                $ratio,
                $context,
                $extraContext
            );
            $token = Plugin::getInstance()->generationRequests->create($userId);
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
        } catch (ForbiddenHttpException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->cancelGeneration($token, $userId);
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            $this->cancelGeneration($token, $userId);
            Craft::$app->getErrorHandler()->logException($exception);
            throw new BadRequestHttpException('The image generation request could not be queued.', 0, $exception);
        }

        return $this->asJson([
            'success' => true,
            'generation' => [
                'token' => $token,
                'status' => GenerationRequests::STATUS_QUEUED,
            ],
        ]);
    }

    public function actionStatus(): Response
    {
        $token = trim((string)$this->request->getRequiredBodyParam('token'));
        $userId = (int)Craft::$app->getUser()->getId();

        try {
            $generation = Plugin::getInstance()->generationRequests->get($token, $userId);
        } catch (RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        $status = (string)$generation['status'];
        $payload = [
            'success' => true,
            'generation' => [
                'token' => $token,
                'status' => $status,
            ],
        ];
        if ($status === GenerationRequests::STATUS_COMPLETE) {
            try {
                $result = Plugin::getInstance()->generatedResults->get($token, $userId);
                $payload['result'] = [
                    'token' => $token,
                    'mimeType' => $result['mimeType'],
                    'extension' => $result['extension'],
                    'width' => $result['width'],
                    'height' => $result['height'],
                    'previewUrl' => UrlHelper::cpUrl('image-creator-ai/api/preview', [
                        'token' => $token,
                    ]),
                ];
            } catch (RuntimeException) {
                $payload['generation']['status'] = GenerationRequests::STATUS_FAILED;
                $payload['generation']['error'] = 'The image generation request expired. Generate it again.';
            }
        } elseif ($status === GenerationRequests::STATUS_FAILED) {
            $payload['generation']['error'] = (string)$generation['error'];
        } elseif ($status === GenerationRequests::STATUS_CANCELLED) {
            $payload['generation']['error'] = 'Image generation was cancelled.';
        }

        $response = $this->asJson($payload);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public function actionSave(): Response
    {
        $request = $this->request;
        $token = trim((string)$request->getRequiredBodyParam('token'));
        $filename = trim((string)$request->getBodyParam('filename', ''));
        $target = $this->arrayParam('target');
        $userId = (int)Craft::$app->getUser()->getId();

        try {
            $asset = Plugin::getInstance()->assetCreator->save($token, $userId, $target, $filename);
        } catch (ForbiddenHttpException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            throw new BadRequestHttpException('The generated image could not be saved as an Asset.', 0, $exception);
        }

        try {
            Plugin::getInstance()->generationRequests->consume($token, $userId);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
        }

        return $this->asJson([
            'success' => true,
            'asset' => $this->assetData($asset),
        ]);
    }

    public function actionDiscard(): Response
    {
        return $this->actionCancel();
    }

    public function actionCancel(): Response
    {
        $token = trim((string)$this->request->getRequiredBodyParam('token'));
        $userId = (int)Craft::$app->getUser()->getId();
        try {
            Plugin::getInstance()->generationRequests->cancel($token, $userId);
            Plugin::getInstance()->generatedResults->discardIfExists($token, $userId);
        } catch (RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        return $this->asJson(['success' => true]);
    }

    public function actionPreview(string $token): Response
    {
        try {
            $result = Plugin::getInstance()->generatedResults->get(
                trim($token),
                (int)Craft::$app->getUser()->getId()
            );
            $contents = file_get_contents($result['path']);
            if (!is_string($contents)) {
                throw new RuntimeException('The generated image could not be read.');
            }
        } catch (RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', $result['mimeType']);
        $response->headers->set('Content-Length', (string)strlen($contents));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->content = $contents;

        return $response;
    }

    /** @return array<string, mixed> */
    private function arrayParam(string $name): array
    {
        $value = $this->request->getRequiredBodyParam($name);
        if (!is_array($value)) {
            throw new BadRequestHttpException("The {$name} data is invalid.");
        }

        return $value;
    }

    /** @return array<string, string> */
    private function contextParam(mixed $value): array
    {
        if (!is_array($value)) {
            throw new BadRequestHttpException('The context fields are invalid.');
        }

        $context = [];
        foreach ($value as $row) {
            if (!is_array($row) || !isset($row['key']) || !is_string($row['key'])) {
                throw new BadRequestHttpException('The context fields are invalid.');
            }
            $key = trim($row['key']);
            $fieldValue = $row['value'] ?? '';
            if ($key === '' || isset($context[$key]) || !is_scalar($fieldValue)) {
                throw new BadRequestHttpException('The context fields are invalid.');
            }
            $context[$key] = (string)$fieldValue;
        }

        return $context;
    }

    private function cancelGeneration(?string $token, int $userId): void
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

    /**
     * @return array{
     *     id: int,
     *     uid: string,
     *     title: string,
     *     filename: string,
     *     kind: string,
     *     width: int|null,
     *     height: int|null,
     *     url: string|null,
     *     thumbUrl: string|null,
     *     cpEditUrl: string|null
     * }
     */
    private function assetData(Asset $asset): array
    {
        $cacheBust = static function(?string $url): ?string {
            if (!is_string($url) || $url === '') {
                return null;
            }

            return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . time();
        };

        try {
            $url = $cacheBust($asset->getUrl());
        } catch (Throwable) {
            $url = null;
        }
        try {
            $thumbUrl = $cacheBust(Craft::$app->getAssets()->getThumbUrl($asset, 200, 200, false));
        } catch (Throwable) {
            $thumbUrl = $url;
        }
        try {
            $cpEditUrl = $asset->getCpEditUrl();
        } catch (Throwable) {
            $cpEditUrl = null;
        }

        return [
            'id' => (int)$asset->id,
            'uid' => (string)$asset->uid,
            'title' => (string)$asset->title,
            'filename' => (string)$asset->filename,
            'kind' => (string)$asset->kind,
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'url' => $url,
            'thumbUrl' => $thumbUrl,
            'cpEditUrl' => $cpEditUrl,
        ];
    }
}
