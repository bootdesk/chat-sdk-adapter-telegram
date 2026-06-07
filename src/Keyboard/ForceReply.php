<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class ForceReply implements ReplyMarkup
{
    public function __construct(
        public readonly ?string $inputFieldPlaceholder = null,
        public readonly ?bool $selective = null,
    ) {}

    public function toArray(): array
    {
        $result = ['force_reply' => true];

        if ($this->inputFieldPlaceholder !== null) {
            $result['input_field_placeholder'] = $this->inputFieldPlaceholder;
        }

        if ($this->selective !== null) {
            $result['selective'] = $this->selective;
        }

        return $result;
    }
}
