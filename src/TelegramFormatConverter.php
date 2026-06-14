<?php

namespace BootDesk\ChatSDK\Telegram;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
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

    public function convertMarkdown(string $text): string
    {
        return $this->fromAst($this->toAst($text));
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
        $closeStack = [];

        /** @var array{type: string, counter: int}[] */
        $listStack = [];

        while ($event = $walker->next()) {
            $node = $event->getNode();
            $entering = $event->isEntering();
            $class = get_class($node);

            if ($entering) {
                switch ($class) {
                    case 'League\CommonMark\Node\Inline\Text':
                        /** @var Text $node */
                        $output .= $this->escapeMarkdownV2($node->getLiteral());
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Strong':
                    case 'League\CommonMark\Extension\CommonMark\Node\Block\Heading':
                        $output .= '*';
                        $closeStack[] = '*';
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis':
                        $output .= '_';
                        $closeStack[] = '_';
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Code':
                        /** @var Code $node */
                        $output .= '`'.$this->escapeCodeBlock($node->getLiteral()).'`';
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\FencedCode':
                        /** @var FencedCode $node */
                        $lang = $node->getInfo() ?? '';
                        $code = $this->escapeCodeBlock(rtrim($node->getLiteral(), "\n"));
                        $output .= "```{$lang}\n{$code}\n```";
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Link':
                        /** @var Link $node */
                        $output .= '[';
                        $closeStack[] = ']('.$this->escapeLinkUrl($node->getUrl()).')';
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote':
                        $output .= '> ';
                        $closeStack[] = '';
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak':
                        $output .= "---\n";
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\ListBlock':
                        /** @var ListBlock $node */
                        $data = $node->getListData();
                        $listStack[] = [
                            'type' => $data->type,
                            'counter' => (int) ($data->start ?? 1),
                        ];
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\ListItem':
                        $listInfo = $listStack !== [] ? $listStack[array_key_last($listStack)] : null;
                        if ($listInfo !== null && $listInfo['type'] === 'ordered') {
                            $output .= "{$listInfo['counter']}. ";
                            $listStack[array_key_last($listStack)]['counter']++;
                        } else {
                            $output .= '- ';
                        }
                        break;

                    case 'League\CommonMark\Node\Inline\Newline':
                        $output .= "\n";
                        break;

                    case 'League\CommonMark\Node\Block\Paragraph':
                        // Container — content rendered by children
                        break;

                }
            } else {
                switch ($class) {
                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Strong':
                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis':
                    case 'League\CommonMark\Extension\CommonMark\Node\Inline\Link':
                    case 'League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote':
                        if ($closeStack !== []) {
                            $output .= array_pop($closeStack);
                        }
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\Heading':
                        if ($closeStack !== []) {
                            $output .= array_pop($closeStack);
                        }
                        $output .= "\n\n";
                        break;

                    case 'League\CommonMark\Node\Block\Paragraph':
                        if ($listStack === []) {
                            $output .= "\n\n";
                        }
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\ListItem':
                        $output .= "\n";
                        break;

                    case 'League\CommonMark\Extension\CommonMark\Node\Block\ListBlock':
                        array_pop($listStack);
                        $output .= "\n";
                        break;
                }
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
