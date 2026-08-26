<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

interface ProviderInterface
{
    public function generate(string $prompt, string $ratio): GeneratedImage;
}
