# adapter-telegram

Telegram adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Telegram`

## files

- `TelegramAdapter` — implements `Adapter` using Telegram Bot API (sendMessage, sendChatAction, etc.)
- `TelegramFormatConverter` — Telegram MarkdownV2 ↔ CommonMark AST
- `TelegramCards` — Card model → Telegram Inline Keyboard / HTML
- `Keyboard/` — custom keyboard value objects (ReplyKeyboardMarkup, InlineKeyboardMarkup, ForceReply, ReplyKeyboardRemove, KeyboardButton, InlineKeyboardButton, KeyboardButtonPollType, ReplyMarkup interface)

## registration

`src/register.php` registers `'telegram' => TelegramAdapter::class` via `AdapterRegistry`

## constructor

```php
new TelegramAdapter(
    string $botToken,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format

`telegram:{chatId}` or `telegram:{chatId}:{messageThreadId}` (forum topic) — e.g. `telegram:-123456789` / `telegram:-123456789:9876`

## contracts implemented

- `HandlesActions` — `parseAction()` for callback queries
- `HandlesReactions` — `parseReaction()` for message reactions
- `HandlesSlashCommands` — `parseSlashCommand()` for commands starting with `/`
- `MustRehydrateAttachments` — restores `Attachment::fetchData` after queue deserialization via `getFile` API + `file/bot<token>` download
- `SupportsEditMessages` / `SupportsDeleteMessages` — edit/delete via Bot API

## webhook flow

1. `verifyWebhook` — extracts update from request body
2. `parseWebhook` — handles messages, callback queries, inline queries; detects private chats as DMs

## features

- Post/edit/delete messages, inline keyboards
- Custom reply keyboards, force reply, keyboard removal (via `Keyboard\` value objects in `metadata.reply_markup`)
- `reply_to_message_id` support (via `PostableMessage->replyToMessageId`)
- Typing indicators (sendChatAction)
- Fetch chat member count, chat info (getChat)
- File uploads via `sendDocument` (multipart), URL-based attachments via `sendPhoto`/`sendDocument`/`sendAudio`/`sendVideo`/`sendSticker`/`sendAnimation`/`sendVideoNote`
- Card `imageUrl` uses `sendPhoto` with HTML caption
- Inbound attachment extraction (photo, document, video, audio, voice, sticker, animation, video_note)
- Streaming: splits into chunks (4096 chars max per Telegram limit), sends incrementally
- Supports HTML parse mode for rich text

## keyboard value objects

All implement `ReplyMarkup` interface with `toArray(): array`. Pass through `$message->metadata['reply_markup']`.

| Class                  | Fields                                                                                                     |
| ---------------------- | ---------------------------------------------------------------------------------------------------------- |
| `KeyboardButton`       | `text`, `requestContact`, `requestLocation`, `requestPoll` (KeyboardButtonPollType), `webApp` (WebAppInfo) |
| `InlineKeyboardButton` | `text`, `callbackData`, `url`, `webApp` (WebAppInfo)                                                       |
| `WebAppInfo`           | `url`                                                                                                      |
| `ReplyKeyboardMarkup`  | `keyboard: KeyboardButton[][]`, `resizeKeyboard`, `oneTimeKeyboard`, `inputFieldPlaceholder`, `selective`  |
| `InlineKeyboardMarkup` | `inlineKeyboard: InlineKeyboardButton[][]`                                                                 |
| `ForceReply`           | `inputFieldPlaceholder`, `selective`                                                                       |
| `ReplyKeyboardRemove`  | `selective`                                                                                                |

`KeyboardButtonPollType` constants: `QUIZ`, `REGULAR`.

Metadata `reply_markup` takes precedence over card-generated inline keyboard. Raw arrays also accepted.

## config (laravel)

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
],
```
