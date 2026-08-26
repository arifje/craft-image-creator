<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\models;

use craft\base\Model;

final class Prompt extends Model
{
    public string $prompt = '';

    /** @return array<int, mixed> */
    public function defineRules(): array
    {
        return [
            [['prompt'], 'filter', 'filter' => 'trim'],
            [['prompt'], 'required'],
            [['prompt'], 'string', 'max' => 20_000],
        ];
    }
}
