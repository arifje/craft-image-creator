<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

use arifje\craftimagecreator\models\Settings;
use RuntimeException;
use yii\base\Component;

final class ProviderRegistry extends Component
{
    public function get(string $provider): ProviderInterface
    {
        return match ($provider) {
            Settings::PROVIDER_OPENAI => new OpenAiProvider(),
            Settings::PROVIDER_XAI => new XAiProvider(),
            Settings::PROVIDER_GOOGLE => new GoogleProvider(),
            default => throw new RuntimeException('The selected AI image provider is invalid.'),
        };
    }
}
