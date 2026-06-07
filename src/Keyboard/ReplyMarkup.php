<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

interface ReplyMarkup
{
    public function toArray(): array;
}
