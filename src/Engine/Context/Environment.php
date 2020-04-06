<?php

namespace ricwein\Templater\Engine\Context;

use ricwein\Templater\Processors\Processor;
use ricwein\Tokenizer\Result\BaseToken;

class Environment
{
    /**
     * @var array<string, array<int, array<BaseToken|Processor>>>
     */
    private array $blocks;

    /**
     * @var array<string, string>
     */
    private array $resolvedBlocks;

    /**
     * Environment constructor.
     * @param array<string, array<int, array<BaseToken|Processor>>> $blocks
     * @param array<string, string> $resolvedBlocks
     */
    public function __construct(array $blocks = [], array $resolvedBlocks = [])
    {
        $this->blocks = $blocks;
        $this->resolvedBlocks = $resolvedBlocks;
    }

    /**
     * @param string $blockName
     * @param array<BaseToken|Processor> $block
     */
    public function addBlock(string $blockName, array $block): void
    {
        if (!isset($this->blocks[$blockName])) {
            $this->blocks[$blockName] = [];
        }
        $this->blocks[$blockName][] = $block;
    }

    /**
     * @param string $blockName
     * @return null|array<int, array<BaseToken|Processor>>
     */
    public function getBlockVersions(string $blockName): ?array
    {
        if (!isset($this->blocks[$blockName])) {
            return null;
        }

        return $this->blocks[$blockName];
    }

    public function addResolvedBlock(string $blockName, string $content): void
    {
        $this->resolvedBlocks[$blockName] = $content;
    }

    public function getResolvedBlock(string $blockName): ?string
    {
        if (isset($this->resolvedBlocks[$blockName])) {
            return $this->resolvedBlocks[$blockName];
        }
        return null;
    }
}
