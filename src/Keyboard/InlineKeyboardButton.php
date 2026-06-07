<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Keyboard;

class InlineKeyboardButton
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $callbackData = null,
        public readonly ?string $url = null,
        public readonly ?WebAppInfo $webApp = null,
    ) {}

    public function toArray(): array
    {
        $result = ['text' => $this->text];

        if ($this->callbackData !== null) {
            $result['callback_data'] = $this->callbackData;
        }

        if ($this->url !== null) {
            $result['url'] = $this->url;
        }

        if ($this->webApp instanceof WebAppInfo) {
            $result['web_app'] = $this->webApp->toArray();
        }

        return $result;
    }
}
