<?php

namespace BootDesk\ChatSDK\Telegram\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Telegram\TelegramFormatConverter;
use PHPUnit\Framework\TestCase;

class TelegramFormatConverterTest extends TestCase
{
    private TelegramFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new TelegramFormatConverter;
    }

    public function test_escape_markdown_v2(): void
    {
        $escaped = $this->converter->escapeMarkdownV2('Hello *world* [test]');
        $this->assertStringContainsString('\*', $escaped);
        $this->assertStringContainsString('\[', $escaped);
    }

    public function test_to_ast_parses_markdown(): void
    {
        $ast = $this->converter->toAst('Hello **world**');
        $this->assertNotNull($ast);
    }

    public function test_from_ast_renders_markdown_v2(): void
    {
        $ast = $this->converter->toAst('Hello **world**');
        $result = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function test_truncate_under_limit_unchanged(): void
    {
        $text = str_repeat('a', 100);
        $this->assertSame($text, $this->converter->truncateForTelegram($text, 200));
    }

    public function test_truncate_over_limit(): void
    {
        $text = str_repeat('a', 4100);
        $truncated = $this->converter->truncateForTelegram($text, 4096);
        $this->assertLessThanOrEqual(4096, strlen($truncated));
    }

    public function test_render_postable_card(): void
    {
        $card = Card::make()->header('Hello')->section(fn ($s) => $s->text('World'));
        $message = PostableMessage::card($card);

        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_render_postable_text_escapes(): void
    {
        $message = PostableMessage::text('Hello *world*');

        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('\*', $result);
    }

    public function test_to_telegram_payload_card(): void
    {
        $card = Card::make()->header('Card')->actions([Button::primary('Go', 'go')]);
        $message = PostableMessage::card($card);

        $payload = $this->converter->toTelegramPayload($message);
        $this->assertArrayHasKey('reply_markup', $payload);
        $this->assertArrayHasKey('inline_keyboard', $payload['reply_markup']);
    }

    public function test_to_telegram_payload_text(): void
    {
        $message = PostableMessage::text('Hello world');

        $payload = $this->converter->toTelegramPayload($message);
        $this->assertArrayHasKey('text', $payload);
        $this->assertArrayHasKey('parse_mode', $payload);
    }

    public function test_from_ast_with_various_markdown(): void
    {
        $tests = [
            'Hello **bold**' => 'bold',
            'Hello *italic*' => 'italic',
            '`code`' => 'code',
            "```\nblock\n```" => 'block',
            '[link](https://x.com)' => 'link',
            '# heading' => 'heading',
            '> quote' => 'quote',
        ];

        foreach ($tests as $markdown => $expected) {
            $ast = $this->converter->toAst($markdown);
            $result = $this->converter->fromAst($ast);
            $this->assertStringContainsString($expected, $result, "Failed for: {$markdown}");
        }
    }

    public function test_escape_code_block(): void
    {
        $message = PostableMessage::text("`code` and ```\nblock\n```");
        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('code', $result);
    }

    public function test_truncate_for_telegram(): void
    {
        $text = str_repeat('a', 4097);
        $result = $this->converter->truncateForTelegram($text);
        $this->assertLessThanOrEqual(4096, strlen($result));
    }
}
