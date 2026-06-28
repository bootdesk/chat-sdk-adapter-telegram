<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramListItemRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof ListItem) {
            return null;
        }

        $parent = $node->parent();
        $ordered = false;
        $counter = 1;

        if ($parent instanceof ListBlock) {
            $data = $parent->getListData();
            $ordered = $data->type === ListBlock::TYPE_ORDERED;

            if ($ordered) {
                $index = 0;
                foreach ($parent->children() as $child) {
                    if ($child === $node) {
                        break;
                    }
                    $index++;
                }
                $counter = ($data->start ?? 1) + $index;
            }
        }

        $prefix = $ordered ? "{$counter}\\." : '\\-';
        $content = $childRenderer->renderNodes($node->children());

        return $prefix.' '.trim($content);
    }
}
