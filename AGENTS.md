# adapter-telegram

Telegram adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Telegram`

## files
- `TelegramAdapter` — implements `Adapter` using Telegram Bot API (sendMessage, sendChatAction, etc.)
- `TelegramFormatConverter` — Telegram MarkdownV2 ↔ CommonMark AST
- `TelegramCards` — Card model → Telegram Inline Keyboard / HTML

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
`telegram:{chatId}:{messageId}` — e.g. `telegram:-123456789:9876`

## webhook flow
1. `verifyWebhook` — extracts update from request body
2. `parseWebhook` — handles messages, callback queries, inline queries; detects private chats as DMs

## features
- Post/edit/delete messages, inline keyboards
- Typing indicators (sendChatAction)
- Fetch chat member count, chat info (getChat)
- File uploads via `sendDocument` (multipart), URL-based attachments via `sendPhoto`/`sendDocument`/`sendAudio`/`sendVideo`
- Card `imageUrl` uses `sendPhoto` with HTML caption
- Inbound attachment extraction (photo, document, video, audio, voice)
- Streaming: splits into chunks (4096 chars max per Telegram limit), sends incrementally
- Supports HTML parse mode for rich text

## config (laravel)
```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
],
```
