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

    public function test_convert_markdown_bold_to_telegram(): void
    {
        $result = $this->converter->convertMarkdown('what I know is **timezone**');
        $this->assertSame('what I know is *timezone*', $result);
    }

    public function test_convert_markdown_italic_to_telegram(): void
    {
        $result = $this->converter->convertMarkdown('hello *world*');
        $this->assertSame('hello _world_', $result);
    }

    public function test_convert_markdown_code_to_telegram(): void
    {
        $result = $this->converter->convertMarkdown('use `code` here');
        $this->assertSame('use `code` here', $result);
    }

    public function test_convert_markdown_link_to_telegram(): void
    {
        $result = $this->converter->convertMarkdown('[click](https://x.com)');
        $this->assertSame('[click](https://x.com)', $result);
    }

    public function test_convert_markdown_plain_text_preserved(): void
    {
        $result = $this->converter->convertMarkdown('Hello world');
        $this->assertSame('Hello world', $result);
    }

    public function test_convert_escapes_literal_special_chars(): void
    {
        $result = $this->converter->convertMarkdown('5 * 10 = 50');
        $this->assertStringContainsString('\*', $result);
        $this->assertStringContainsString('5', $result);
        $this->assertStringContainsString('10', $result);
        $this->assertStringContainsString('50', $result);
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

    public function test_unordered_list(): void
    {
        $result = $this->converter->convertMarkdown("- item1\n- item2");
        $this->assertSame("- item1\n- item2", $result);
    }

    public function test_ordered_list(): void
    {
        $result = $this->converter->convertMarkdown("1. one\n2. two");
        $this->assertSame("1. one\n2. two", $result);
    }

    public function test_paragraphs_separated(): void
    {
        $result = $this->converter->convertMarkdown("para 1\n\npara 2");
        $this->assertStringContainsString('para 1', $result);
        $this->assertStringContainsString('para 2', $result);
    }

    public function test_thematic_break(): void
    {
        $result = $this->converter->convertMarkdown("text\n\n---\n\nmore");
        $this->assertStringContainsString('---', $result);
    }

    public function test_newline_in_paragraph(): void
    {
        $result = $this->converter->convertMarkdown("line 1\nline 2");
        $this->assertStringContainsString("\n", $result);
    }

    public function test_blockquote_preserved(): void
    {
        $result = $this->converter->convertMarkdown('> quote');
        $this->assertSame('> quote', $result);
    }

    public function test_convert_markdown_heading(): void
    {
        $result = $this->converter->convertMarkdown('# Hello');
        $this->assertSame('*Hello*', $result);
    }

    public function test_fenced_code_no_trailing_extra_newline(): void
    {
        $result = $this->converter->convertMarkdown("```\ncode\n```");
        $this->assertStringContainsString('code', $result);
        $this->assertStringNotContainsString("code\n\n", $result);

        $lines = explode("\n", $result);
        $this->assertStringStartsWith('```', $result);
        $this->assertStringEndsWith('```', trim($result));
    }
}
