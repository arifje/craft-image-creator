<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services;

use arifje\craftimagecreator\db\Table;
use arifje\craftimagecreator\models\Prompt;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use RuntimeException;
use yii\base\Component;

final class Prompts extends Component
{
    public const DEFAULT_PROMPT = 'Create a compelling, editorial-quality image based on the supplied context.';

    public function getPrompt(): string
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(Table::PROMPTS)) {
            throw new RuntimeException('Image Creator prompt storage is unavailable. Run the pending Craft migrations.');
        }

        $prompt = (new Query())
            ->select(['prompt'])
            ->from(Table::PROMPTS)
            ->where(['id' => 1])
            ->scalar($db);

        if (!is_string($prompt)) {
            throw new RuntimeException('The Image Creator prompt is missing. Run the pending Craft migrations.');
        }

        return trim($prompt);
    }

    public function save(Prompt $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        if (!$db->tableExists(Table::PROMPTS)) {
            throw new RuntimeException('Image Creator prompt storage is unavailable. Run the pending Craft migrations.');
        }

        Db::upsert(
            Table::PROMPTS,
            ['id' => 1, 'prompt' => $model->prompt],
            ['prompt' => $model->prompt],
            [],
            true,
            $db
        );

        return true;
    }
}
