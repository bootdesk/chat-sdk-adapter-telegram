<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class KeyboardButtonPollType
{
    public const QUIZ = 'quiz';

    public const REGULAR = 'regular';

    public function __construct(
        public readonly string $type,
    ) {}
}
