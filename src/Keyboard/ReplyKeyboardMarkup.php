<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class ReplyKeyboardMarkup implements ReplyMarkup
{
    public function __construct(
        public readonly array $keyboard,
        public readonly ?bool $resizeKeyboard = null,
        public readonly ?bool $oneTimeKeyboard = null,
        public readonly ?string $inputFieldPlaceholder = null,
        public readonly ?bool $selective = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'keyboard' => array_map(
                fn (array $row): array => array_map(
                    fn (KeyboardButton $btn): array => $btn->toArray(),
                    $row,
                ),
                $this->keyboard,
            ),
        ];

        if ($this->resizeKeyboard !== null) {
            $result['resize_keyboard'] = $this->resizeKeyboard;
        }

        if ($this->oneTimeKeyboard !== null) {
            $result['one_time_keyboard'] = $this->oneTimeKeyboard;
        }

        if ($this->inputFieldPlaceholder !== null) {
            $result['input_field_placeholder'] = $this->inputFieldPlaceholder;
        }

        if ($this->selective !== null) {
            $result['selective'] = $this->selective;
        }

        return $result;
    }
}
