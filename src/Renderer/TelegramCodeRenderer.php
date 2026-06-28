<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramCodeRenderer implements NodeRendererInterface
{
    private const CODE_SPECIAL_CHARS = '/([`\\\\])/';

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof Code) {
            return null;
        }

        return '`'.preg_replace(self::CODE_SPECIAL_CHARS, '\\\\$1', $node->getLiteral()).'`';
    }
}
