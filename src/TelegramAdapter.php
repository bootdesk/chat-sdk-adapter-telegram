<?php

namespace BootDesk\ChatSDK\Telegram;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesActions;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Contracts\SupportsDeleteMessages;
use BootDesk\ChatSDK\Core\Contracts\SupportsEditMessages;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\FileUpload;
use BootDesk\ChatSDK\Core\LocalizationType;
use BootDesk\ChatSDK\Core\LocalizationValue;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TelegramAdapter implements Adapter, HandlesActions, HandlesReactions, HandlesSlashCommands, HasAuthorInfo, RequiresAsyncResponse, SupportsDeleteMessages, SupportsEditMessages
{
    private const ATTACHMENT_UPLOADS = [
        'audio' => ['field' => 'audio', 'method' => 'sendAudio'],
        'file' => ['field' => 'document', 'method' => 'sendDocument'],
        'image' => ['field' => 'photo', 'method' => 'sendPhoto'],
        'video' => ['field' => 'video', 'method' => 'sendVideo'],
    ];

    protected ?string $botUserId = null;

    protected TelegramFormatConverter $formatConverter;

    protected ?string $secretToken;

    public function __construct(
        protected readonly string $botToken,
        protected readonly ClientInterface $httpClient,
        ?string $secretToken = null,
        protected readonly string $apiUrl = 'https://api.telegram.org',
        protected readonly ?Psr17Factory $psrFactory = null,
    ) {
        $this->secretToken = $secretToken;
        $this->formatConverter = new TelegramFormatConverter;
    }

    public function getName(): string
    {
        return 'telegram';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        if ($this->secretToken !== null) {
            $headerToken = $request->getHeaderLine('x-telegram-bot-api-secret-token');

            if ($headerToken === '' || ! hash_equals($this->secretToken, $headerToken)) {
                throw new AuthenticationException('Invalid Telegram secret token');
            }
        }

        return null;
    }

    private ?string $pendingCallbackQueryId = null;

    public function parseAction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $update = json_decode($body, true);

        if ($update === null || ! isset($update['callback_query'])) {
            return null;
        }

        $cq = $update['callback_query'];
        $message = $cq['message'] ?? [];

        if ($message === []) {
            return null;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId === '') {
            return null;
        }

        $decoded = TelegramCards::decodeCallbackData($cq['data'] ?? null);
        $actionId = $decoded['actionId'] ?? 'telegram_callback';
        $value = is_string($decoded['value'] ?? null) ? $decoded['value'] : null;

        $messageId = "{$chatId}:{$message['message_id']}";
        $threadId = "telegram:{$chatId}";
        $from = $cq['from'] ?? [];

        $this->pendingCallbackQueryId = $cq['id'] ?? null;

        $tgLocalizations = [];
        if (isset($from['language_code'])) {
            $tgLocalizations[] = new LocalizationValue(LocalizationType::Language, $from['language_code']);
        }

        return [
            'author' => new Author(
                ...$tgLocalizations,
                id: (string) ($from['id'] ?? ''),
                name: $from ? trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) : null,
                isBot: $from['is_bot'] ?? false,
            ),
            'actionId' => $actionId,
            'value' => $value,
            'threadId' => $threadId,
            'messageId' => $messageId,
            'userId' => (string) ($from['id'] ?? ''),
            'isBot' => $from['is_bot'] ?? false,
            'isMe' => false,
            'raw' => $body,
            'triggerId' => null,
            'callbackQueryId' => $cq['id'] ?? null,
            'originId' => null,
        ];
    }

    public function acknowledgeAction(?string $callbackQueryId): ?ResponseInterface
    {
        $id = $callbackQueryId ?? $this->pendingCallbackQueryId;
        $this->pendingCallbackQueryId = null;

        if ($id === null) {
            return null;
        }

        $this->apiCall('answerCallbackQuery', [
            'callback_query_id' => $id,
        ]);

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $update = json_decode($body, true);

        if ($update === null) {
            return null;
        }

        $tgMessage = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? $update['edited_channel_post']
            ?? null;

        if ($tgMessage === null) {
            return null;
        }

        $rawText = $tgMessage['text'] ?? '';

        if ($rawText === '' || $rawText[0] !== '/') {
            return null;
        }

        $entities = $tgMessage['entities'] ?? [];
        $hasBotCommand = false;
        $cmdText = '';
        $cmdEnd = 0;

        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') === 'bot_command') {
                $hasBotCommand = true;
                $cmdText = substr($rawText, $entity['offset'], $entity['length']);
                $cmdEnd = $entity['offset'] + $entity['length'];
                break;
            }
        }

        if (! $hasBotCommand) {
            return null;
        }

        $text = trim(substr($rawText, $cmdEnd));
        $from = $tgMessage['from'] ?? [];
        $chatId = (string) ($tgMessage['chat']['id'] ?? '');

        $channelId = $chatId !== '' ? "telegram:{$chatId}" : '';

        $tgLocalizations = [];
        if ($from && isset($from['language_code'])) {
            $tgLocalizations[] = new LocalizationValue(LocalizationType::Language, $from['language_code']);
        }

        return [
            'author' => new Author(
                ...$tgLocalizations,
                id: $from ? (string) ($from['id'] ?? '') : '',
                name: $from ? trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) : null,
                isBot: $from['is_bot'] ?? false,
            ),
            'command' => $cmdText,
            'text' => $text,
            'userId' => $from ? (string) ($from['id'] ?? '') : '',
            'isBot' => $from['is_bot'] ?? false,
            'isMe' => false,
            'channelId' => $channelId,
            'triggerId' => null,
            'raw' => $body,
        ];
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $update = json_decode($body, true);

        if (! is_array($update)) {
            return null;
        }

        $reactionUpdate = $update['message_reaction'] ?? null;

        if ($reactionUpdate === null) {
            return null;
        }

        $oldReactions = [];
        foreach ($reactionUpdate['old_reaction'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'emoji') {
                $oldReactions[] = $r['emoji'];
            }
        }

        $newReactions = [];
        foreach ($reactionUpdate['new_reaction'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'emoji') {
                $newReactions[] = $r['emoji'];
            }
        }

        $added = null;
        foreach ($newReactions as $emoji) {
            if (! in_array($emoji, $oldReactions, true)) {
                $added = $emoji;
                break;
            }
        }

        $removed = null;
        foreach ($oldReactions as $emoji) {
            if (! in_array($emoji, $newReactions, true)) {
                $removed = $emoji;
                break;
            }
        }

        $emoji = $added ?? $removed;

        if ($emoji === null) {
            return null;
        }

        $chatId = (string) $reactionUpdate['chat']['id'];
        $messageThreadId = $reactionUpdate['message_thread_id'] ?? null;
        $user = $reactionUpdate['user'] ?? [];
        $userId = isset($user['id']) ? (string) $user['id'] : '';

        $threadId = $this->encodeThreadId([
            'chatId' => $chatId,
            'messageThreadId' => $messageThreadId,
        ]);

        return [
            'author' => new Author(
                id: $userId,
                isBot: $user['is_bot'] ?? false,
            ),
            'emoji' => $emoji,
            'rawEmoji' => $emoji,
            'added' => $added !== null,
            'threadId' => $threadId,
            'messageId' => (string) $reactionUpdate['message_id'],
            'userId' => $userId,
            'raw' => $update,
            'originId' => null,
        ];
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $update = json_decode($body, true);

        if ($update === null) {
            throw new AdapterException('Invalid JSON payload from Telegram');
        }

        $tgMessage = $update['message']
            ?? $update['edited_message']
            ?? $update['channel_post']
            ?? $update['edited_channel_post']
            ?? null;

        // Handle callback queries (inline keyboard clicks)
        if (isset($update['callback_query']) && $tgMessage === null) {
            $cq = $update['callback_query'];
            $tgMessage = $cq['message'] ?? null;
        }

        if ($tgMessage === null) {
            throw new AdapterException('No message found in Telegram update');
        }

        $chatId = (string) $tgMessage['chat']['id'];
        $messageThreadId = $tgMessage['message_thread_id'] ?? null;
        $messageId = (string) $tgMessage['message_id'];
        $text = $tgMessage['text'] ?? $tgMessage['caption'] ?? '';
        $from = $tgMessage['from'] ?? null;

        // Apply entity formatting
        $entities = $tgMessage['entities'] ?? $tgMessage['caption_entities'] ?? [];
        if (! empty($entities) && $text !== '') {
            $text = $this->applyEntities($text, $entities);
        }

        $threadId = $this->encodeThreadId([
            'chatId' => $chatId,
            'messageThreadId' => $messageThreadId,
        ]);

        $isDM = ($tgMessage['chat']['type'] ?? '') === 'private';

        return new Message(
            id: $messageId,
            threadId: $threadId,
            author: new Author(
                id: $from ? (string) $from['id'] : '',
                name: $from ? trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) : '',
                isBot: $from['is_bot'] ?? false,
            ),
            text: $text,
            attachments: $this->extractAttachments($tgMessage),
            isMention: str_contains($text, "@{$this->botUserId}"),
            isDM: $isDM,
            raw: $body,
        );
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $tgMessage): array
    {
        $attachments = [];

        $photo = $tgMessage['photo'] ?? null;
        if ($photo !== null) {
            $largest = $photo[count($photo) - 1];
            $attachments[] = new Attachment(
                type: 'image',
                url: null,
                name: null,
                mimeType: 'image/jpeg',
                size: $largest['file_size'] ?? null,
                width: $largest['width'] ?? null,
                height: $largest['height'] ?? null,
                fetchData: null,
                fetchMetadata: ['file_id' => $largest['file_id']],
            );
        }

        $document = $tgMessage['document'] ?? null;
        if ($document !== null) {
            $attachments[] = new Attachment(
                type: 'file',
                url: null,
                name: $document['file_name'] ?? null,
                mimeType: $document['mime_type'] ?? null,
                size: $document['file_size'] ?? null,
                fetchMetadata: ['file_id' => $document['file_id']],
            );
        }

        $video = $tgMessage['video'] ?? null;
        if ($video !== null) {
            $attachments[] = new Attachment(
                type: 'video',
                url: null,
                name: $video['file_name'] ?? null,
                mimeType: $video['mime_type'] ?? null,
                size: $video['file_size'] ?? null,
                width: $video['width'] ?? null,
                height: $video['height'] ?? null,
                fetchMetadata: ['file_id' => $video['file_id']],
            );
        }

        $audio = $tgMessage['audio'] ?? null;
        if ($audio !== null) {
            $attachments[] = new Attachment(
                type: 'audio',
                url: null,
                name: $audio['file_name'] ?? null,
                mimeType: $audio['mime_type'] ?? null,
                size: $audio['file_size'] ?? null,
                fetchMetadata: ['file_id' => $audio['file_id']],
            );
        }

        $voice = $tgMessage['voice'] ?? null;
        if ($voice !== null) {
            $attachments[] = new Attachment(
                type: 'audio',
                name: 'voice',
                mimeType: $voice['mime_type'] ?? null,
                size: $voice['file_size'] ?? null,
                fetchMetadata: ['file_id' => $voice['file_id']],
            );
        }

        return $attachments;
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $chatId = $platformData['chatId'] ?? '';
        $threadId = $platformData['messageThreadId'] ?? '';

        if ($threadId !== '') {
            return "telegram:{$chatId}:{$threadId}";
        }

        return "telegram:{$chatId}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 3);

        return [
            'chatId' => $parts[1] ?? '',
            'messageThreadId' => $parts[2] ?? null,
        ];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $this->decodeThreadId($threadId)['chatId'];
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $params = ['chat_id' => $decoded['chatId']];

        if ($decoded['messageThreadId'] !== null) {
            $params['message_thread_id'] = (int) $decoded['messageThreadId'];
        }

        $text = $this->getTextContent($message);
        $keyboard = $message->isCard() ? TelegramCards::toInlineKeyboard($message->content) : null;

        // Files (binary upload) take priority — Telegram supports 1 file per message
        if ($message->files !== []) {
            $file = $message->files[0];
            $uploadParams = $params;
            if ($text !== '') {
                $uploadParams['caption'] = $text;
                $uploadParams['parse_mode'] = 'MarkdownV2';
            }
            $response = $this->apiCallMultipart('sendDocument', $uploadParams, $file);

            return new SentMessage(
                id: (string) $response['message_id'],
                threadId: $threadId,
                timestamp: isset($response['date']) ? (string) $response['date'] : null,
            );
        }

        // URL-based attachments (1 per message)
        if ($message->attachments !== []) {
            $att = $message->attachments[0];
            $upload = self::ATTACHMENT_UPLOADS[$att->type] ?? self::ATTACHMENT_UPLOADS['file'];
            $params[$upload['field']] = $att->url;
            if ($text !== '') {
                $params['caption'] = $text;
                $params['parse_mode'] = 'MarkdownV2';
            }
            if ($keyboard !== null) {
                $params['reply_markup'] = json_encode($keyboard);
            }
            $response = $this->apiCall($upload['method'], $params);

            return new SentMessage(
                id: (string) $response['message_id'],
                threadId: $threadId,
                timestamp: isset($response['date']) ? (string) $response['date'] : null,
            );
        }

        // Card with imageUrl → sendPhoto
        if ($message->isCard() && $message->content->getImageUrl() !== null) {
            $card = $message->content;
            $params['photo'] = $card->getImageUrl();
            $params['caption'] = TelegramCards::toHtmlText($card);
            $params['parse_mode'] = 'HTML';
            if ($keyboard !== null) {
                $params['reply_markup'] = json_encode($keyboard);
            }
            $response = $this->apiCall('sendPhoto', $params);

            return new SentMessage(
                id: (string) $response['message_id'],
                threadId: $threadId,
                timestamp: isset($response['date']) ? (string) $response['date'] : null,
            );
        }

        // Default: sendMessage
        $content = $message->isCard() ? TelegramCards::toHtmlText($message->content) : $message->getTextContent();
        $parseMode = $message->isCard() ? 'HTML' : 'MarkdownV2';
        $params['text'] = $content;
        $params['parse_mode'] = $parseMode;

        if (! $message->isCard()) {
            $params['text'] = $this->formatConverter->escapeMarkdownV2($params['text']);
        }

        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        $response = $this->apiCall('sendMessage', $params);

        return new SentMessage(
            id: (string) $response['message_id'],
            threadId: $threadId,
            timestamp: isset($response['date']) ? (string) $response['date'] : null,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $text = $this->getTextContent($message);
        $params = [
            'chat_id' => $decoded['chatId'],
            'message_id' => (int) $messageId,
        ];

        if ($message->attachments !== [] && $message->attachments[0]->type === 'image') {
            $params['caption'] = $text;
            $params['parse_mode'] = 'HTML';
            $response = $this->apiCall('editMessageCaption', $params);
        } else {
            $parseMode = $message->isCard() ? 'HTML' : 'MarkdownV2';
            $params['text'] = $text;
            $params['parse_mode'] = $parseMode;
            if (! $message->isCard()) {
                $params['text'] = $this->formatConverter->escapeMarkdownV2($params['text']);
            }
            $response = $this->apiCall('editMessageText', $params);
        }

        return new SentMessage(
            id: (string) ($response['message_id'] ?? $messageId),
            threadId: $threadId,
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->apiCall('deleteMessage', [
            'chat_id' => $decoded['chatId'],
            'message_id' => (int) $messageId,
        ]);
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->apiCall('setMessageReaction', [
            'chat_id' => $decoded['chatId'],
            'message_id' => (int) $messageId,
            'reaction' => [['type' => 'emoji', 'emoji' => $emoji]],
        ]);
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Telegram doesn't have a direct "remove reaction" — setMessageReaction with empty clears all
        $decoded = $this->decodeThreadId($threadId);
        $this->apiCall('setMessageReaction', [
            'chat_id' => $decoded['chatId'],
            'message_id' => (int) $messageId,
            'reaction' => [],
        ]);
    }

    public function startTyping(string $threadId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->apiCall('sendChatAction', [
            'chat_id' => $decoded['chatId'],
            'action' => 'typing',
        ]);
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        // Telegram doesn't have a direct message history fetch for regular chats.
        // This is a best-effort via getUpdates or forwarding.
        return new FetchResult(messages: []);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        return new ThreadInfo(
            id: $threadId,
            channelId: $decoded['chatId'],
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $response = $this->apiCall('getChat', ['chat_id' => $channelId]);

        return new ChannelInfo(
            id: (string) $response['id'],
            name: $response['title'] ?? ($response['username'] ?? ''),
            isPrivate: ($response['type'] ?? '') === 'private',
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $response = $this->apiCall('getChat', ['chat_id' => $userId]);

        if (($response['type'] ?? '') !== 'private') {
            return null;
        }

        return new UserInfo(
            id: (string) $response['id'],
            name: trim(($response['first_name'] ?? '').' '.($response['last_name'] ?? '')),
        );
    }

    public function getAuthorInfo(Author $author): Author
    {
        $response = $this->apiCall('getChat', ['chat_id' => $author->id]);

        if (($response['type'] ?? '') !== 'private') {
            return $author;
        }

        if (! isset($response['language_code'])) {
            return $author;
        }

        return $author->withLocalizations(
            new LocalizationValue(LocalizationType::Language, $response['language_code']),
        );
    }

    public function openDM(string $userId): ?string
    {
        // Telegram doesn't need explicit DM opening — just send to user ID
        return $userId;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        try {
            $me = $this->apiCall('getMe', []);
            $this->botUserId = (string) $me['id'];
        } catch (AdapterException) {
            // Will retry later
        }
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    private function getTextContent(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return TelegramCards::toHtmlText($message->content);
        }

        return $message->getTextContent();
    }

    protected function apiCallMultipart(string $method, array $params, ?FileUpload $file = null): array
    {
        $url = "{$this->apiUrl}/bot{$this->botToken}/{$method}";

        $builder = new MultipartStreamBuilder($this->psrFactory ?? new Psr17Factory);

        if ($file instanceof FileUpload) {
            $builder->addResource('document', $file->data, [
                'filename' => $file->filename,
                'headers' => ['Content-Type' => $file->mimeType ?? 'application/octet-stream'],
            ]);
        }

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $builder->addData($value, [
                    'Content-Disposition' => 'form-data; name="'.$key.'"',
                ]);
            } elseif (is_array($value) || is_object($value)) {
                $encoded = json_encode($value);
                if ($encoded !== false) {
                    $builder->addData($encoded, [
                        'Content-Disposition' => 'form-data; name="'.$key.'"',
                        'Content-Type' => 'application/json',
                    ]);
                }
            }
        }

        $stream = $builder->build();
        $boundary = $builder->getBoundary();

        $factory = $this->psrFactory ?? new Psr17Factory;
        $request = $factory->createRequest('POST', $url)
            ->withHeader('Content-Type', "multipart/form-data; boundary={$boundary}")
            ->withBody($stream);

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Telegram API: {$method}");
        }

        if (($data['ok'] ?? false) === false) {
            $error = $data['description'] ?? ($data['error_code'] ?? 'unknown_error');
            $errorCode = $data['error_code'] ?? 0;

            if (in_array($errorCode, [401, 403], true) || str_contains($error, 'Unauthorized')) {
                throw new AuthenticationException("Telegram API authentication error ({$method}): {$error}");
            }

            throw new AdapterException("Telegram API error ({$method}): {$error}");
        }

        $result = $data['result'] ?? $data;

        return is_array($result) ? $result : ['ok' => true];
    }

    protected function apiCall(string $method, array $params): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}/bot{$this->botToken}/{$method}";

        $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
        $request = $factory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Telegram API: {$method}");
        }

        if (($data['ok'] ?? false) === false) {
            $error = $data['description'] ?? ($data['error_code'] ?? 'unknown_error');
            $errorCode = $data['error_code'] ?? 0;

            if (in_array($errorCode, [401, 403], true) || str_contains($error, 'Unauthorized')) {
                throw new AuthenticationException("Telegram API authentication error ({$method}): {$error}");
            }

            throw new AdapterException("Telegram API error ({$method}): {$error}");
        }

        $result = $data['result'] ?? $data;

        return is_array($result) ? $result : ['ok' => true];
    }

    protected function applyEntities(string $text, array $entities): string
    {
        // Sort by offset descending so replacements don't shift offsets
        usort($entities, fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);

        foreach ($entities as $entity) {
            $start = $entity['offset'];
            $end = $start + $entity['length'];
            $entityText = substr($text, $start, $entity['length']);

            $replacement = match ($entity['type']) {
                'bold' => "**{$entityText}**",
                'italic' => "*{$entityText}*",
                'code' => "`{$entityText}`",
                'pre' => "```\n{$entityText}\n```",
                'strikethrough' => "~~{$entityText}~~",
                'text_link' => isset($entity['url']) ? "[{$entityText}]({$entity['url']})" : $entityText,
                default => null,
            };

            if ($replacement !== null) {
                $text = substr($text, 0, $start).$replacement.substr($text, $end);
            }
        }

        return $text;
    }
}
