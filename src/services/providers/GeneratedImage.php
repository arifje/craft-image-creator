<?php

declare(strict_types=1);

namespace arifje\craftimagecreator\services\providers;

final class GeneratedImage
{
    public string $bytes;
    public string $declaredMimeType;

    public function __construct(string $bytes, string $declaredMimeType = '')
    {
        $this->bytes = $bytes;
        $this->declaredMimeType = $declaredMimeType;
    }
}
