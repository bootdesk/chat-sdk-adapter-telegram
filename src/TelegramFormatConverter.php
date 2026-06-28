<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramBlockQuoteRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramCodeRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramEmphasisRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramFencedCodeRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramHeadingRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramImageRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramLinkRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramListBlockRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramListItemRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramNewlineRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramParagraphRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramStrikethroughRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramStrongRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramTextRenderer;
use BootDesk\ChatSDK\Telegram\Renderer\TelegramThematicBreakRenderer;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;

class TelegramFormatConverter extends BaseFormatConverter
{
    public function toAst(string $text): Document
    {
        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return TelegramCards::toHtmlText($message->content);
        }

        return $this->escapeMarkdownV2((string) $message->content);
    }

    public function toTelegramPayload(PostableMessage $message): array
    {
        if ($message->isCard()) {
            $keyboard = TelegramCards::toInlineKeyboard($message->content);

            return [
                'text' => $message->content->getFallbackText(),
                'reply_markup' => $keyboard,
            ];
        }

        $content = (string) $message->content;

        return [
            'text' => $this->escapeMarkdownV2($content),
            'parse_mode' => 'MarkdownV2',
        ];
    }

    public function convertMarkdown(string $text): string
    {
        return $this->fromAst($this->toAst($text));
    }

    public function escapeMarkdownV2(string $text): string
    {
        return preg_replace('/([_*[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text);
    }

    public function truncateForTelegram(string $text, int $limit = 4096): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        $ellipsis = '\\.\\.\\.';
        $slice = substr($text, 0, $limit - strlen($ellipsis));

        return $slice.$ellipsis;
    }

    protected function registerRenderers(): void
    {
        parent::registerRenderers();

        $this->addRenderer(Text::class, new TelegramTextRenderer, 10);
        $this->addRenderer(Strong::class, new TelegramStrongRenderer, 10);
        $this->addRenderer(Emphasis::class, new TelegramEmphasisRenderer, 10);
        $this->addRenderer(Strikethrough::class, new TelegramStrikethroughRenderer, 10);
        $this->addRenderer(Heading::class, new TelegramHeadingRenderer, 10);
        $this->addRenderer(Link::class, new TelegramLinkRenderer, 10);
        $this->addRenderer(Image::class, new TelegramImageRenderer, 10);
        $this->addRenderer(Code::class, new TelegramCodeRenderer, 10);
        $this->addRenderer(FencedCode::class, new TelegramFencedCodeRenderer, 10);
        $this->addRenderer(BlockQuote::class, new TelegramBlockQuoteRenderer, 10);
        $this->addRenderer(ThematicBreak::class, new TelegramThematicBreakRenderer, 10);
        $this->addRenderer(ListBlock::class, new TelegramListBlockRenderer, 10);
        $this->addRenderer(ListItem::class, new TelegramListItemRenderer, 10);
        $this->addRenderer(Paragraph::class, new TelegramParagraphRenderer, 10);
        $this->addRenderer(Newline::class, new TelegramNewlineRenderer, 10);
    }
}
