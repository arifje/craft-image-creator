<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\migrations;

use arifje\craftimagecreator\db\Table;
use arifje\craftimagecreator\services\Prompts;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\App;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::PROMPTS)) {
            $this->createTable(Table::PROMPTS, [
                'id' => $this->primaryKey(),
                'prompt' => $this->mediumText()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }

        $promptExists = (new Query())
            ->from(Table::PROMPTS)
            ->where(['id' => 1])
            ->exists($this->db);

        if (!$promptExists) {
            $this->insert(Table::PROMPTS, [
                'id' => 1,
                'prompt' => $this->initialPrompt(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::PROMPTS);

        return true;
    }

    protected function initialPrompt(): string
    {
        $projectConfigPrompt = Craft::$app->getProjectConfig()->get(
            'plugins.craft-image-creator.settings.prompt'
        );
        $prompt = is_string($projectConfigPrompt)
            ? $projectConfigPrompt
            : Prompts::DEFAULT_PROMPT;

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
