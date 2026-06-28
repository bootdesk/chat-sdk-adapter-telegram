<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramTextRenderer implements NodeRendererInterface
{
    private const SPECIAL_CHARS = '/([_*[\]()~`>#+\-=|{}.!\\\\])/';

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof Text) {
            return null;
        }

        return preg_replace(self::SPECIAL_CHARS, '\\\\$1', $node->getLiteral());
    }
}
