<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\App;

final class m260826_000001_create_prompt_table extends Migration
{
    private const TABLE = '{{%craftimagecreator_prompts}}';
    private const DEFAULT_PROMPT = 'Create a compelling, editorial-quality image based on the supplied context.';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'prompt' => $this->mediumText()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        $promptExists = (new Query())
            ->from(self::TABLE)
            ->where(['id' => 1])
            ->exists($this->db);

        if (!$promptExists) {
            $this->insert(self::TABLE, [
                'id' => 1,
                'prompt' => $this->legacyPrompt(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260826_000001_create_prompt_table cannot be reverted.\n";

        return false;
    }

    private function legacyPrompt(): string
    {
        $projectConfigPrompt = Craft::$app->getProjectConfig()->get(
            'plugins.craft-image-creator.settings.prompt'
        );
        $prompt = is_string($projectConfigPrompt)
            ? $projectConfigPrompt
            : self::DEFAULT_PROMPT;

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('craft-image-creator');
        if (
            is_array($fileConfig) &&
            isset($fileConfig['prompt']) &&
            is_string($fileConfig['prompt'])
        ) {
            $prompt = $fileConfig['prompt'];
        }

        return trim((string)App::parseEnv($prompt));
    }
}
