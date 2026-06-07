<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class InlineKeyboardMarkup implements ReplyMarkup
{
    public function __construct(
        public readonly array $inlineKeyboard,
    ) {}

    public function toArray(): array
    {
        return [
            'inline_keyboard' => array_map(
                fn (array $row): array => array_map(
                    fn (InlineKeyboardButton $btn): array => $btn->toArray(),
                    $row,
                ),
                $this->inlineKeyboard,
            ),
        ];
    }
}
