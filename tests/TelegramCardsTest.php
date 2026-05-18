<?php

namespace BootDesk\ChatSDK\Telegram\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Exceptions\ValidationException;
use BootDesk\ChatSDK\Telegram\TelegramCards;
use PHPUnit\Framework\TestCase;

class TelegramCardsTest extends TestCase
{
    public function test_card_with_buttons(): void
    {
        $card = Card::make()
            ->header('Choose')
            ->actions([
                Button::primary('Yes', 'yes'),
                Button::danger('No', 'no'),
            ]);

        $keyboard = TelegramCards::toInlineKeyboard($card);

        $this->assertNotNull($keyboard);
        $this->assertArrayHasKey('inline_keyboard', $keyboard);
        $this->assertCount(1, $keyboard['inline_keyboard']);
        $this->assertCount(2, $keyboard['inline_keyboard'][0]);
        $this->assertSame('Yes', $keyboard['inline_keyboard'][0][0]['text']);
        $this->assertSame('No', $keyboard['inline_keyboard'][0][1]['text']);
    }

    public function test_card_without_buttons_returns_null(): void
    {
        $card = Card::make()->header('No buttons');
        $this->assertNull(TelegramCards::toInlineKeyboard($card));
    }

    public function test_encode_decode_callback_data(): void
    {
        $encoded = TelegramCards::encodeCallbackData('approve', '123');
        $decoded = TelegramCards::decodeCallbackData($encoded);

        $this->assertSame('approve', $decoded['actionId']);
        $this->assertSame('123', $decoded['value']);
    }

    public function test_decode_null_data(): void
    {
        $decoded = TelegramCards::decodeCallbackData(null);
        $this->assertSame('telegram_callback', $decoded['actionId']);
    }

    public function test_decode_non_prefixed_data(): void
    {
        $decoded = TelegramCards::decodeCallbackData('raw_data');
        $this->assertSame('raw_data', $decoded['actionId']);
    }

    public function test_encode_callback_data_too_large_throws(): void
    {
        $this->expectException(ValidationException::class);
        TelegramCards::encodeCallbackData(str_repeat('a', 100), str_repeat('b', 100));
    }

    public function test_decode_invalid_json_fallback(): void
    {
        $decoded = TelegramCards::decodeCallbackData('chat:not-json');
        $this->assertSame('chat:not-json', $decoded['actionId']);
    }
}
