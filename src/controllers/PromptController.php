<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\controllers;

use arifje\craftimagecreator\models\Prompt;
use arifje\craftimagecreator\Plugin;
use arifje\craftimagecreator\utilities\ImagePrompt;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

final class PromptController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePermission('accessCp');
        $this->requirePermission('utility:' . ImagePrompt::id());

        if (!Craft::$app->getUtilities()->checkAuthorization(ImagePrompt::class)) {
            throw new ForbiddenHttpException('User is not authorized to modify the Image Creator prompt.');
        }

        return true;
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $model = new Prompt();
        $prompt = $this->request->getBodyParam('prompt', '');
        $model->prompt = is_string($prompt) ? $prompt : '';

        try {
            $saved = Plugin::getInstance()->prompts->save($model);
        } catch (Throwable $exception) {
            Craft::$app->getErrorHandler()->logException($exception);
            $saved = false;
        }

        if (!$saved) {
            $session = Craft::$app->getSession();
            $session->setFlash(ImagePrompt::FLASH_PROMPT, $model->prompt);
            $session->setFlash(ImagePrompt::FLASH_ERRORS, $model->getErrors());
            $this->setFailFlash(Craft::t('craft-image-creator', 'Couldn’t save the image prompt.'));

            return $this->redirectToPostedUrl(null, 'utilities/' . ImagePrompt::id());
        }

        $this->setSuccessFlash(Craft::t('craft-image-creator', 'Image prompt saved.'));

        return $this->redirectToPostedUrl(null, 'utilities/' . ImagePrompt::id());
    }
}
