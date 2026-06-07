# bootdesk/chat-sdk-adapter-telegram

Telegram adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-telegram
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable       | Description                          | Example                 |
| -------------- | ------------------------------------ | ----------------------- |
| `bot_token`    | Telegram Bot Token (from @BotFather) | `123456:ABC-DEF...`     |
| `http_client`  | PSR-18 HTTP client instance          | `new GuzzleHttp\Client` |
| `secret_token` | Webhook secret token                 | `my-secret...`          |

```php
use BootDesk\ChatSDK\Telegram\TelegramAdapter;

$adapter = new TelegramAdapter(
    botToken: env('TELEGRAM_BOT_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    secretToken: env('TELEGRAM_SECRET_TOKEN'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'telegram' => [
    'bot_token'    => env('TELEGRAM_BOT_TOKEN'),
    'secret_token' => env('TELEGRAM_SECRET_TOKEN'),
],
```

## Quick Example

```php
// Post a message to a chat
$adapter->postMessage('telegram:123456789', 'Hello from laravel-bootdesk!');

// Post in a topic forum
$adapter->postMessage('telegram:-100123456789:42', 'Topic message');
```

## Thread ID Format

| Format                                | Description                  |
| ------------------------------------- | ---------------------------- |
| `telegram:{chatId}`                   | Direct message or group chat |
| `telegram:{chatId}:{messageThreadId}` | Topic within a forum         |

## Webhook

Telegram sends updates via webhook. Verify requests using the `secret_token` parameter set during webhook registration.

## Custom Keyboards

Pass keyboard value objects via `PostableMessage::$metadata['reply_markup']`:

```php
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Telegram\Keyboard\KeyboardButton;
use BootDesk\ChatSDK\Telegram\Keyboard\ReplyKeyboardMarkup;

// Custom reply keyboard with location request
$thread->post(new PostableMessage(
    content: 'Choose an option:',
    metadata: [
        'reply_markup' => new ReplyKeyboardMarkup(
            keyboard: [
                [new KeyboardButton('Help')],
                [new KeyboardButton('Share Location', requestLocation: true)],
                [new KeyboardButton('Share Contact', requestContact: true)],
            ],
            resizeKeyboard: true,
            oneTimeKeyboard: true,
        ),
    ],
));
```

### Types

| Value object                    | Telegram API equivalent |
| ------------------------------- | ----------------------- |
| `ReplyKeyboardMarkup`           | Custom reply keyboard   |
| `InlineKeyboardMarkup`          | Inline keyboard (standalone) |
| `ForceReply`                    | Force reply             |
| `ReplyKeyboardRemove`           | Remove custom keyboard  |
| `KeyboardButton`               | text / request_location / request_contact / request_poll / web_app |
| `InlineKeyboardButton`         | text / callback_data / url / web_app |
| `WebAppInfo`                   | url |

Metadata `reply_markup` takes precedence over the card-generated inline keyboard. Raw arrays also accepted for power users.

### Reply-to-Message

```php
$thread->post(new PostableMessage(
    content: 'Reply to this',
    replyToMessageId: '42',
));
```

## Feature Matrix

| Feature              | Supported |
| -------------------- | --------- |
| Post messages        | ✓         |
| Edit messages        | ✓         |
| Delete messages      | ✓         |
| Reactions            | ✓         |
| Reply keyboards      | ✓         |
| Inline keyboards     | ✓         |
| Force reply          | ✓         |
| Typing indicator     | ✓         |
| Fetch messages       | ✓         |
| Fetch thread info    | ✓         |
| Fetch channel info   | ✓         |
| Get user             | ✓         |
| Open DM              | ✓         |
| Stream               | ✓         |
| Reply-to-message     | ✓         |

## Notes

Supports inline keyboards, custom reply keyboards, force reply, bot commands, group chats, and topic forums.

## Documentationn

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
