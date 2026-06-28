<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramHeadingRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof Heading) {
            return null;
        }

        return '__'.$childRenderer->renderNodes($node->children())."__\n\n";
    }
}
