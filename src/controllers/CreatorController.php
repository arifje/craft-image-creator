<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\controllers;

use arifje\craftimagecreator\Plugin;
use Craft;
use craft\elements\Asset;
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

        if ($action->id === 'preview') {
            if (!$this->request->getIsGet()) {
                throw new MethodNotAllowedHttpException('The preview endpoint only accepts GET requests.');
            }
        } else {
            $this->requirePostRequest();
            $this->requireAcceptsJson();
        }

        return true;
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

        try {
            $resolvedTarget = Plugin::getInstance()->assetCreator->resolveTarget($target);
            $generatedImage = Plugin::getInstance()->imageGenerator->generate(
                $provider,
                $ratio,
                $context,
                $extraContext
            );
            $result = Plugin::getInstance()->generatedResults->store(
                $generatedImage,
                $userId,
                $provider,
                $ratio,
                $resolvedTarget->binding()
            );
        } catch (ForbiddenHttpException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            throw new BadRequestHttpException('The image could not be generated.', 0, $exception);
        }

        $result['previewUrl'] = UrlHelper::cpUrl('image-creator-ai/api/preview', [
            'token' => $result['token'],
        ]);

        return $this->asJson([
            'success' => true,
            'result' => $result,
        ]);
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

        return $this->asJson([
            'success' => true,
            'asset' => $this->assetData($asset),
        ]);
    }

    public function actionDiscard(): Response
    {
        $token = trim((string)$this->request->getRequiredBodyParam('token'));
        try {
            Plugin::getInstance()->generatedResults->discard(
                $token,
                (int)Craft::$app->getUser()->getId()
            );
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
     *     thumbUrl: string|null
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
        ];
    }
}
