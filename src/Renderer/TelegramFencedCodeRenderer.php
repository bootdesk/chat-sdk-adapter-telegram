<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Telegram\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TelegramFencedCodeRenderer implements NodeRendererInterface
{
    private const CODE_SPECIAL_CHARS = '/([`\\\\])/';

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (! $node instanceof FencedCode) {
            return null;
        }

        $lang = $node->getInfo() ?? '';
        $code = preg_replace(self::CODE_SPECIAL_CHARS, '\\\\$1', rtrim($node->getLiteral(), "\n"));

        return "```{$lang}\n{$code}\n```";
    }
}
