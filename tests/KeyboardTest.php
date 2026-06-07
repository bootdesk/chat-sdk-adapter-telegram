<?php

namespace BootDesk\ChatSDK\Telegram\Tests;

use BootDesk\ChatSDK\Telegram\Keyboard\ForceReply;
use BootDesk\ChatSDK\Telegram\Keyboard\InlineKeyboardButton;
use BootDesk\ChatSDK\Telegram\Keyboard\InlineKeyboardMarkup;
use BootDesk\ChatSDK\Telegram\Keyboard\KeyboardButton;
use BootDesk\ChatSDK\Telegram\Keyboard\KeyboardButtonPollType;
use BootDesk\ChatSDK\Telegram\Keyboard\ReplyKeyboardMarkup;
use BootDesk\ChatSDK\Telegram\Keyboard\ReplyKeyboardRemove;
use BootDesk\ChatSDK\Telegram\Keyboard\WebAppInfo;
use PHPUnit\Framework\TestCase;

class KeyboardTest extends TestCase
{
    public function test_keyboard_button_text_only(): void
    {
        $btn = new KeyboardButton('Cancel');
        $this->assertSame(['text' => 'Cancel'], $btn->toArray());
    }

    public function test_keyboard_button_with_request_location(): void
    {
        $btn = new KeyboardButton('Share Location', requestLocation: true);
        $this->assertSame(['text' => 'Share Location', 'request_location' => true], $btn->toArray());
    }

    public function test_keyboard_button_with_request_contact(): void
    {
        $btn = new KeyboardButton('Share Contact', requestContact: true);
        $this->assertSame(['text' => 'Share Contact', 'request_contact' => true], $btn->toArray());
    }

    public function test_keyboard_button_with_request_poll(): void
    {
        $btn = new KeyboardButton('Poll', requestPoll: new KeyboardButtonPollType('quiz'));
        $this->assertSame(['text' => 'Poll', 'request_poll' => ['type' => 'quiz']], $btn->toArray());
    }

    public function test_keyboard_button_with_all_options(): void
    {
        $btn = new KeyboardButton('Send', requestContact: true, requestLocation: true);
        $expected = ['text' => 'Send', 'request_contact' => true, 'request_location' => true];
        $this->assertSame($expected, $btn->toArray());
    }

    public function test_keyboard_button_poll_type_constants(): void
    {
        $this->assertSame('quiz', KeyboardButtonPollType::QUIZ);
        $this->assertSame('regular', KeyboardButtonPollType::REGULAR);
    }

    public function test_inline_keyboard_button_with_callback_data(): void
    {
        $btn = new InlineKeyboardButton('Click', callbackData: 'action_confirm');
        $this->assertSame(['text' => 'Click', 'callback_data' => 'action_confirm'], $btn->toArray());
    }

    public function test_inline_keyboard_button_with_url(): void
    {
        $btn = new InlineKeyboardButton('Visit', url: 'https://example.com');
        $this->assertSame(['text' => 'Visit', 'url' => 'https://example.com'], $btn->toArray());
    }

    public function test_inline_keyboard_button_text_only(): void
    {
        $btn = new InlineKeyboardButton('Text');
        $this->assertSame(['text' => 'Text'], $btn->toArray());
    }

    public function test_reply_keyboard_markup(): void
    {
        $markup = new ReplyKeyboardMarkup(
            keyboard: [
                [new KeyboardButton('A'), new KeyboardButton('B')],
                [new KeyboardButton('Cancel')],
            ],
            resizeKeyboard: true,
            oneTimeKeyboard: true,
        );

        $expected = [
            'keyboard' => [
                [['text' => 'A'], ['text' => 'B']],
                [['text' => 'Cancel']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
        $this->assertSame($expected, $markup->toArray());
    }

    public function test_reply_keyboard_markup_with_optional_fields(): void
    {
        $markup = new ReplyKeyboardMarkup(
            keyboard: [[new KeyboardButton('Yes')]],
            inputFieldPlaceholder: 'Type here...',
            selective: true,
        );

        $result = $markup->toArray();
        $this->assertSame('Type here...', $result['input_field_placeholder']);
        $this->assertTrue($result['selective']);
        $this->assertArrayNotHasKey('resize_keyboard', $result);
        $this->assertArrayNotHasKey('one_time_keyboard', $result);
    }

    public function test_inline_keyboard_markup(): void
    {
        $markup = new InlineKeyboardMarkup(
            inlineKeyboard: [
                [new InlineKeyboardButton('Go', callbackData: 'go')],
                [new InlineKeyboardButton('Help', url: 'https://help.com')],
            ],
        );

        $expected = [
            'inline_keyboard' => [
                [['text' => 'Go', 'callback_data' => 'go']],
                [['text' => 'Help', 'url' => 'https://help.com']],
            ],
        ];
        $this->assertSame($expected, $markup->toArray());
    }

    public function test_force_reply(): void
    {
        $markup = new ForceReply;
        $this->assertSame(['force_reply' => true], $markup->toArray());
    }

    public function test_force_reply_with_placeholder_and_selective(): void
    {
        $markup = new ForceReply(inputFieldPlaceholder: 'Reply...', selective: true);
        $expected = ['force_reply' => true, 'input_field_placeholder' => 'Reply...', 'selective' => true];
        $this->assertSame($expected, $markup->toArray());
    }

    public function test_reply_keyboard_remove(): void
    {
        $markup = new ReplyKeyboardRemove;
        $this->assertSame(['remove_keyboard' => true], $markup->toArray());
    }

    public function test_reply_keyboard_remove_selective(): void
    {
        $markup = new ReplyKeyboardRemove(selective: true);
        $this->assertSame(['remove_keyboard' => true, 'selective' => true], $markup->toArray());
    }

    public function test_web_app_info(): void
    {
        $app = new WebAppInfo('https://example.com/app');
        $this->assertSame(['url' => 'https://example.com/app'], $app->toArray());
    }

    public function test_inline_keyboard_button_with_web_app(): void
    {
        $btn = new InlineKeyboardButton('Open App', webApp: new WebAppInfo('https://example.com/app'));
        $this->assertSame(
            ['text' => 'Open App', 'web_app' => ['url' => 'https://example.com/app']],
            $btn->toArray(),
        );
    }

    public function test_keyboard_button_with_web_app(): void
    {
        $btn = new KeyboardButton('Open App', webApp: new WebAppInfo('https://example.com/app'));
        $this->assertSame(
            ['text' => 'Open App', 'web_app' => ['url' => 'https://example.com/app']],
            $btn->toArray(),
        );
    }
}
