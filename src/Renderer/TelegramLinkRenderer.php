<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramLinkRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof Link) {
            return null;
        }

        $text = $childRenderer->renderNodes($node->children());
        $url = str_replace([')', '\\'], ['\\)', '\\\\'], $node->getUrl());

        return '['.$text.']('.$url.')';
    }
}
