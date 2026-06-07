<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class WebAppInfo
{
    public function __construct(
        public readonly string $url,
    ) {}

    public function toArray(): array
    {
        return ['url' => $this->url];
    }
}
