<?php

namespace BootDesk\ChatSDK\Telegram\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Telegram\TelegramAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class TelegramAdapterTest extends TestCase
{
    private TelegramAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            private array $responses = [];

            public function __construct()
            {
                $factory = new Psr17Factory;

                $this->responses = [
                    'getMe' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => ['id' => 42, 'is_bot' => true, 'first_name' => 'TestBot']]))
                    ),
                    'sendMessage' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => ['message_id' => 100, 'date' => 1700000000]]))
                    ),
                    'editMessageText' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => ['message_id' => 100, 'date' => 1700000001]]))
                    ),
                    'deleteMessage' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => true]))
                    ),
                    'setMessageReaction' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => true]))
                    ),
                    'sendChatAction' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => true]))
                    ),
                    'getChat' => $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['ok' => true, 'result' => ['id' => 12345, 'type' => 'private', 'first_name' => 'John', 'last_name' => 'Doe', 'username' => 'johndoe']]))
                    ),
                ];
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $uri = (string) $request->getUri();
                foreach ($this->responses as $key => $response) {
                    if (str_contains($uri, $key)) {
                        return $response;
                    }
                }

                $factory = new Psr17Factory;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['ok' => true, 'result' => []]))
                );
            }
        };

        $this->adapter = new TelegramAdapter(
            botToken: '123456:ABC-DEF',
            secretToken: 'my_secret',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    public function test_get_name(): void
    {
        $this->assertSame('telegram', $this->adapter->getName());
    }

    public function test_thread_id_encoding_with_topic(): void
    {
        $id = $this->adapter->encodeThreadId(['chatId' => '-100123', 'messageThreadId' => 42]);
        $this->assertSame('telegram:-100123:42', $id);
    }

    public function test_thread_id_encoding_without_topic(): void
    {
        $id = $this->adapter->encodeThreadId(['chatId' => '12345']);
        $this->assertSame('telegram:12345', $id);
    }

    public function test_thread_id_decoding(): void
    {
        $decoded = $this->adapter->decodeThreadId('telegram:-100123:42');
        $this->assertSame('-100123', $decoded['chatId']);
        $this->assertSame('42', $decoded['messageThreadId']);
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('-100123', $this->adapter->channelIdFromThreadId('telegram:-100123:42'));
    }

    public function test_verify_webhook_valid_token(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withHeader('x-telegram-bot-api-secret-token', 'my_secret')
            ->withBody($this->factory->createStream('{"update_id":1}'));

        $result = $this->adapter->verifyWebhook($request);
        $this->assertNull($result);
    }

    public function test_verify_webhook_invalid_token(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withHeader('x-telegram-bot-api-secret-token', 'wrong')
            ->withBody($this->factory->createStream('{"update_id":1}'));

        $this->expectException(AuthenticationException::class);
        $this->adapter->verifyWebhook($request);
    }

    public function test_parse_webhook_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $body = json_encode([
            'update_id' => 1,
            'message' => [
                'message_id' => 100,
                'chat' => ['id' => 12345, 'type' => 'private'],
                'from' => ['id' => 999, 'first_name' => 'John', 'is_bot' => false],
                'text' => 'Hello bot',
                'date' => 1700000000,
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withHeader('x-telegram-bot-api-secret-token', 'my_secret')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('100', $message->id);
        $this->assertSame('telegram:12345', $message->threadId);
        $this->assertSame('999', $message->author->id);
        $this->assertSame('Hello bot', $message->text);
        $this->assertTrue($message->isDM);
    }

    public function test_parse_webhook_with_topic(): void
    {
        $body = json_encode([
            'update_id' => 2,
            'message' => [
                'message_id' => 200,
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'from' => ['id' => 888, 'first_name' => 'Jane', 'is_bot' => false],
                'text' => 'Topic msg',
                'message_thread_id' => 42,
                'date' => 1700000000,
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withHeader('x-telegram-bot-api-secret-token', 'my_secret')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('telegram:-100123:42', $message->threadId);
    }

    public function test_parse_webhook_with_entities(): void
    {
        $body = json_encode([
            'update_id' => 3,
            'message' => [
                'message_id' => 300,
                'chat' => ['id' => 12345, 'type' => 'private'],
                'from' => ['id' => 999, 'first_name' => 'John', 'is_bot' => false],
                'text' => 'This is bold text',
                'entities' => [['offset' => 8, 'length' => 4, 'type' => 'bold']],
                'date' => 1700000000,
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withHeader('x-telegram-bot-api-secret-token', 'my_secret')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertStringContainsString('**bold**', $message->text);
    }

    public function test_parse_slash_command_detects_bot_command_entity(): void
    {
        $body = json_encode([
            'update_id' => 10,
            'message' => [
                'message_id' => 400,
                'from' => ['id' => 7859184066, 'is_bot' => false, 'first_name' => 'Vin'],
                'chat' => ['id' => 7859184066, 'type' => 'private'],
                'date' => 1779043654,
                'text' => '/unsubscribe',
                'entities' => [['offset' => 0, 'length' => 12, 'type' => 'bot_command']],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/unsubscribe', $result['command']);
        $this->assertSame('', $result['text']);
        $this->assertSame('7859184066', $result['userId']);
        $this->assertSame('telegram:7859184066', $result['channelId']);
        $this->assertFalse($result['isBot']);
        $this->assertNull($result['triggerId']);
    }

    public function test_parse_slash_command_with_text(): void
    {
        $body = json_encode([
            'update_id' => 11,
            'message' => [
                'message_id' => 401,
                'from' => ['id' => 123, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => 456, 'type' => 'group'],
                'date' => 1779043655,
                'text' => '/status all good',
                'entities' => [['offset' => 0, 'length' => 7, 'type' => 'bot_command']],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseSlashCommand($request);

        $this->assertNotNull($result);
        $this->assertSame('/status', $result['command']);
        $this->assertSame('all good', $result['text']);
    }

    public function test_parse_slash_command_returns_null_for_regular_message(): void
    {
        $body = json_encode([
            'update_id' => 12,
            'message' => [
                'message_id' => 402,
                'from' => ['id' => 123, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => 456, 'type' => 'private'],
                'date' => 1779043656,
                'text' => 'Hello bot',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_slash_command_returns_null_for_text_starting_with_slash_without_entity(): void
    {
        $body = json_encode([
            'update_id' => 13,
            'message' => [
                'message_id' => 403,
                'from' => ['id' => 123, 'is_bot' => false, 'first_name' => 'Test'],
                'chat' => ['id' => 456, 'type' => 'private'],
                'date' => 1779043657,
                'text' => '/not a command',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseSlashCommand($request));
    }

    public function test_parse_action_detects_callback_query(): void
    {
        $body = json_encode([
            'update_id' => 20,
            'callback_query' => [
                'id' => '6084822427674355899',
                'from' => ['id' => 7859184066, 'is_bot' => false, 'first_name' => 'Vin'],
                'message' => [
                    'message_id' => 97,
                    'chat' => ['id' => 7859184066, 'type' => 'private'],
                    'date' => 1779044255,
                ],
                'data' => 'chat:{"a":"order_confirm","v":"{\"item\":\"123\"}"}',
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('order_confirm', $result['actionId']);
        $this->assertSame('{"item":"123"}', $result['value']);
        $this->assertSame('telegram:7859184066', $result['threadId']);
        $this->assertSame('7859184066:97', $result['messageId']);
        $this->assertSame('7859184066', $result['userId']);
        $this->assertFalse($result['isBot']);
    }

    public function test_parse_action_returns_null_without_callback_query(): void
    {
        $body = json_encode(['update_id' => 21, 'message' => ['text' => 'hello']]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_parse_action_returns_null_for_empty_callback_data(): void
    {
        $body = json_encode([
            'update_id' => 22,
            'callback_query' => [
                'id' => 'abc123',
                'from' => ['id' => 1, 'is_bot' => false],
                'data' => null,
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage(
            'telegram:12345',
            PostableMessage::text('Hello Telegram')
        );

        $this->assertSame('100', $sent->id);
        $this->assertSame('telegram:12345', $sent->threadId);
    }

    public function test_edit_message(): void
    {
        $sent = $this->adapter->editMessage(
            'telegram:12345',
            '99',
            PostableMessage::text('Updated')
        );

        $this->assertSame('100', $sent->id);
    }

    public function test_delete_message(): void
    {
        $this->adapter->deleteMessage('telegram:12345', '99');
        $this->assertTrue(true);
    }

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('telegram:12345', '100', '👍');
        $this->assertTrue(true);
    }

    public function test_start_typing(): void
    {
        $this->adapter->startTyping('telegram:12345');
        $this->assertTrue(true);
    }

    public function test_fetch_channel_info(): void
    {
        // Override mock for this test — we need a group chat response
        $factory = new Psr17Factory;
        $mockClient = new class($factory) implements ClientInterface
        {
            public function __construct(private Psr17Factory $factory) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'ok' => true,
                        'result' => ['id' => -100123, 'type' => 'supergroup', 'title' => 'Test Group'],
                    ]))
                );
            }
        };

        $adapter = new TelegramAdapter(
            botToken: '123456:ABC',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $info = $adapter->fetchChannelInfo('-100123');
        $this->assertSame('-100123', $info->id);
        $this->assertSame('Test Group', $info->name);
        $this->assertFalse($info->isPrivate);
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('12345');
        $this->assertSame('12345', $user->id);
        $this->assertSame('John Doe', $user->name);
    }

    public function test_open_dm_returns_user_id(): void
    {
        $this->assertSame('999', $this->adapter->openDM('999'));
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_initialize_resolves_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertSame('42', $this->adapter->getBotUserId());
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Choose')
            ->actions([Button::primary('Go', 'go')]);

        $sent = $this->adapter->postMessage(
            'telegram:12345',
            PostableMessage::card($card)
        );

        $this->assertSame('100', $sent->id);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream(
            'telegram:12345',
            ['Hello ', 'Telegram'],
        );

        $this->assertNotNull($sent);
        $this->assertSame('100', $sent->id);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('telegram:12345', '100', '👍');
        $this->assertTrue(true);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('telegram:-100123');
        $this->assertSame('telegram:-100123', $info->id);
        $this->assertSame('-100123', $info->channelId);
    }

    public function test_fetch_messages_returns_empty(): void
    {
        $result = $this->adapter->fetchMessages('telegram:12345');
        $this->assertCount(0, $result->messages);
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(200)->withBody(
                    $f->createStream(json_encode(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']))
                );
            }
        };

        $adapter = new TelegramAdapter(
            botToken: '123456:BAD',
            httpClient: $mockClient,
            psrFactory: $factory,
        );
        $adapter->initialize($this->createMock(Chat::class));

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('telegram:12345', PostableMessage::text('test'));
    }

    public function test_parse_reaction_added(): void
    {
        $body = json_encode([
            'message_reaction' => [
                'chat' => ['id' => -100123],
                'message_id' => 42,
                'user' => ['id' => 789],
                'date' => 1700000000,
                'old_reaction' => [],
                'new_reaction' => [['type' => 'emoji', 'emoji' => '👍']],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('👍', $result['emoji']);
        $this->assertTrue($result['added']);
    }

    public function test_parse_reaction_removed(): void
    {
        $body = json_encode([
            'message_reaction' => [
                'chat' => ['id' => -100123],
                'message_id' => 42,
                'user' => ['id' => 789],
                'date' => 1700000000,
                'old_reaction' => [['type' => 'emoji', 'emoji' => '👍']],
                'new_reaction' => [],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('👍', $result['emoji']);
        $this->assertFalse($result['added']);
    }

    public function test_parse_reaction_skips_non_message_reaction(): void
    {
        $body = json_encode(['message' => ['text' => 'hello', 'chat' => ['id' => 123]]]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/telegram')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseReaction($request));
    }

    public function test_implements_handles_reactions(): void
    {
        $this->assertInstanceOf(HandlesReactions::class, $this->adapter);
    }
}
