<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\utilities;

use arifje\craftimagecreator\models\Prompt;
use arifje\craftimagecreator\Plugin;
use Craft;
use craft\base\Utility;

final class ImagePrompt extends Utility
{
    public const FLASH_PROMPT = 'craft-image-creator.image-prompt.prompt';
    public const FLASH_ERRORS = 'craft-image-creator.image-prompt.errors';

    public static function displayName(): string
    {
        return Craft::t('craft-image-creator', 'Image Creator Prompt');
    }

    public static function id(): string
    {
        return 'image-creator-prompt';
    }

    /**
     * Craft 5 utility icon.
     */
    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    /**
     * Craft 4 utility icon.
     */
    public static function iconPath(): ?string
    {
        return self::icon();
    }

    public static function contentHtml(): string
    {
        $session = Craft::$app->getSession();
        $postedPrompt = $session->getFlash(self::FLASH_PROMPT, null, true);
        $errors = $session->getFlash(self::FLASH_ERRORS, [], true);

        $model = new Prompt();
        $model->prompt = is_string($postedPrompt)
            ? $postedPrompt
            : Plugin::getInstance()->prompts->getPrompt();

        if (is_array($errors)) {
            $model->addErrors($errors);
        }

        return Craft::$app->getView()->renderTemplate(
            'craft-image-creator/_utilities/image-prompt.twig',
            ['prompt' => $model]
        );
    }
}
