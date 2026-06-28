<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramListBlockRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof ListBlock) {
            return null;
        }

        $items = [];

        foreach ($node->children() as $child) {
            $items[] = trim($childRenderer->renderNodes([$child]));
        }

        return implode("\n", $items);
    }
}
