<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\controllers;

use arifje\craftimagecreator\models\Settings;
use arifje\craftimagecreator\Plugin;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

final class ModelCatalogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePermission('accessCp');
        $this->requireAdmin(false);
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return true;
    }

    public function actionModels(): Response
    {
        $provider = $this->request->getBodyParam('provider');
        if (!is_string($provider) || !in_array($provider, [
            Settings::PROVIDER_OPENAI,
            Settings::PROVIDER_XAI,
            Settings::PROVIDER_GOOGLE,
        ], true)) {
            throw new BadRequestHttpException('Invalid image provider.');
        }

        $force = filter_var(
            $this->request->getBodyParam('refresh', false),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($force === null) {
            throw new BadRequestHttpException('Invalid refresh value.');
        }

        try {
            return $this->asJson([
                'models' => Plugin::getInstance()->modelCatalog->modelIds($provider, $force),
            ]);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);

            $this->response->setStatusCode(502);
            return $this->asJson([
                'message' => Craft::t(
                    'craft-image-creator',
                    'Could not load image models. Check the saved API key and try again.'
                ),
            ]);
        }
    }
}
