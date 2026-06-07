<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class ReplyKeyboardRemove implements ReplyMarkup
{
    public function __construct(
        public readonly ?bool $selective = null,
    ) {}

    public function toArray(): array
    {
        $result = ['remove_keyboard' => true];

        if ($this->selective !== null) {
            $result['selective'] = $this->selective;
        }

        return $result;
    }
}
