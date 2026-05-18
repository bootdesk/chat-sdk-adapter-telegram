<?php

namespace BootDesk\ChatSDK\Telegram;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Cards\Divider;
use BootDesk\ChatSDK\Core\Cards\Link;
use BootDesk\ChatSDK\Core\Cards\Table;
use BootDesk\ChatSDK\Core\Cards\Text;
use BootDesk\ChatSDK\Core\Cards\TextStyle;
use BootDesk\ChatSDK\Core\Exceptions\ValidationException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

class TelegramCards
{
    private static ?MarkdownParser $parser = null;

    private static ?HtmlRenderer $renderer = null;

    private static function renderMarkdown(string $markdown): string
    {
        if (! self::$parser instanceof MarkdownParser) {
            $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => true]);
            $environment->addExtension(new CommonMarkCoreExtension);
            self::$parser = new MarkdownParser($environment);
            self::$renderer = new HtmlRenderer($environment);
        }

        $ast = self::$parser->parse($markdown);
        $html = trim(self::$renderer->renderDocument($ast)->getContent());

        $html = preg_replace('/<(p|div)\s*>/i', '', $html);
        $html = preg_replace('#</(p|div)\s*>#i', '', $html);
        $html = preg_replace('/<strong\s*>/i', '<b>', $html);
        $html = preg_replace('#</strong\s*>#i', '</b>', $html);
        $html = preg_replace('/<em\s*>/i', '<i>', $html);
        $html = preg_replace('#</em\s*>#i', '</i>', $html);
        $html = preg_replace('/<code\s*>/i', '<code>', $html);
        $html = preg_replace('#</code\s*>#i', '</code>', $html);
        $html = preg_replace('/<pre\s*>/i', '<pre>', $html);
        $html = preg_replace('#</pre\s*>#i', '</pre>', $html);

        return trim($html);
    }

    private const CALLBACK_DATA_PREFIX = 'chat:';

    private const CALLBACK_DATA_LIMIT = 64;

    public static function toInlineKeyboard(Card $card): ?array
    {
        $buttons = $card->getButtons();
        $linkButtons = $card->getLinkButtons();

        if ($buttons === [] && $linkButtons === []) {
            return null;
        }

        $row = [];
        foreach ($buttons as $button) {
            $row[] = self::convertButton($button);
        }
        foreach ($linkButtons as $linkButton) {
            $row[] = [
                'text' => $linkButton->label,
                'url' => $linkButton->url,
            ];
        }

        return [
            'inline_keyboard' => [$row],
        ];
    }

    public static function toHtmlText(Card $card): string
    {
        $parts = [];

        if ($card->getHeader() !== null) {
            $parts[] = '<b>'.htmlspecialchars($card->getHeader(), ENT_QUOTES, 'UTF-8').'</b>';
        }

        foreach ($card->getChildren() as $child) {
            if ($child instanceof Text) {
                $content = htmlspecialchars($child->content, ENT_QUOTES, 'UTF-8');
                $parts[] = match ($child->style) {
                    TextStyle::Bold => '<b>'.$content.'</b>',
                    TextStyle::Muted => '<i>'.$content.'</i>',
                    default => $content,
                };
            } elseif ($child instanceof Divider) {
                $parts[] = '---';
            } elseif ($child instanceof Link) {
                $parts[] = '<a href="'.htmlspecialchars($child->url, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($child->label, ENT_QUOTES, 'UTF-8').'</a>';
            } elseif ($child instanceof Table) {
                $parts[] = '<pre>'.htmlspecialchars(Table::renderAsText($child), ENT_QUOTES, 'UTF-8').'</pre>';
            }
        }

        foreach ($card->getSections() as $section) {
            if ($section->getText() !== null) {
                $rendered = self::renderMarkdown($section->getText());
                $parts[] = $rendered;
            }
            foreach ($section->getFields() as $label => $value) {
                $parts[] = '<b>'.htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8').':</b> '.htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            }
        }

        return implode("\n", $parts);
    }

    public static function encodeCallbackData(string $actionId, ?string $value = null): string
    {
        $payload = ['a' => $actionId];
        if ($value !== null) {
            $payload['v'] = $value;
        }

        $data = self::CALLBACK_DATA_PREFIX.json_encode($payload);

        if (strlen($data) > self::CALLBACK_DATA_LIMIT) {
            throw new ValidationException(
                'telegram: Callback payload too large for Telegram (max '.self::CALLBACK_DATA_LIMIT.' bytes).'
            );
        }

        return $data;
    }

    public static function decodeCallbackData(?string $data): array
    {
        if ($data === null || $data === '') {
            return ['actionId' => 'telegram_callback', 'value' => null];
        }

        if (! str_starts_with($data, self::CALLBACK_DATA_PREFIX)) {
            return ['actionId' => $data, 'value' => $data];
        }

        $json = substr($data, strlen(self::CALLBACK_DATA_PREFIX));
        $decoded = json_decode($json, true);

        if (is_array($decoded) && isset($decoded['a'])) {
            return [
                'actionId' => $decoded['a'],
                'value' => $decoded['v'] ?? null,
            ];
        }

        return ['actionId' => $data, 'value' => $data];
    }

    private static function convertButton(Button $button): array
    {
        return [
            'text' => $button->label,
            'callback_data' => self::encodeCallbackData($button->actionId, json_encode($button->data) ?: null),
        ];
    }
}
