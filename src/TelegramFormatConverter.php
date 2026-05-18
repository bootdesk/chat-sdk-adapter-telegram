<?php

namespace BootDesk\ChatSDK\Telegram;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\Text;

class TelegramFormatConverter extends BaseFormatConverter
{
    // MarkdownV2 requires escaping these characters in normal text
    private const SPECIAL_CHARS = '/([_*[\]()~`>#+\-=|{}.!\\\\])/';

    // Inside code blocks, only ` and \ need escaping
    private const CODE_SPECIAL_CHARS = '/([`\\\\])/';

    public function toAst(string $text): Document
    {
        return $this->parseMarkdown($text);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdownV2($ast);
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

    public function escapeMarkdownV2(string $text): string
    {
        return preg_replace(self::SPECIAL_CHARS, '\\\\$1', $text);
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

    private function renderMarkdownV2(Document $ast): string
    {
        $walker = $ast->walker();
        $output = '';
        $stack = [];

        while ($event = $walker->next()) {
            $node = $event->getNode();
            $entering = $event->isEntering();

            if (! $entering) {
                if ($stack !== []) {
                    $output .= array_pop($stack);
                }

                continue;
            }

            $class = get_class($node);

            if ($class === 'League\CommonMark\Node\Inline\Text') {
                /** @var Text $node */
                $output .= $this->escapeMarkdownV2($node->getLiteral());
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Inline\Strong') {
                $output .= '*';
                $stack[] = '*';
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis') {
                $output .= '_';
                $stack[] = '_';
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Inline\Code') {
                /** @var Code $node */
                $output .= '`'.$this->escapeCodeBlock($node->getLiteral()).'`';
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Block\FencedCode') {
                /** @var FencedCode $node */
                $lang = $node->getInfo() ?? '';
                $output .= "```{$lang}\n".$this->escapeCodeBlock($node->getLiteral())."\n```";
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Inline\Link') {
                /** @var Link $node */
                $output .= '[';
                $stack[] = ']('.$this->escapeLinkUrl($node->getUrl()).')';
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Block\Heading') {
                $output .= '*';
                $stack[] = '*';
            } elseif ($class === 'League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote') {
                $output .= '>';
                $stack[] = '';
            }
        }

        return trim($output);
    }

    private function escapeCodeBlock(string $text): string
    {
        return preg_replace(self::CODE_SPECIAL_CHARS, '\\\\$1', $text);
    }

    private function escapeLinkUrl(string $url): string
    {
        return str_replace([')', '\\'], ['\\)', '\\\\'], $url);
    }
}
