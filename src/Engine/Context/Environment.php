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
     * @param string $name
     * @param array<BaseToken|Processor> $block
     */
    public function addBlock(string $name, array $block): void
    {
        if (!isset($this->blocks[$name])) {
            $this->blocks[$name] = [];
        }
        $this->blocks[$name][] = $block;
    }

    /**
     * @param string $name
     * @return null|array<int, array<BaseToken|Processor>>
     */
    public function getBlockVersions(string $name): ?array
    {
        if (!isset($this->blocks[$name])) {
            return null;
        }

        return $this->blocks[$name];
    }

    public function addResolvedBlock(string $name, string $content): void
    {
        $this->resolvedBlocks[$name] = $content;
    }

    public function getResolvedBlock(string $name): ?string
    {
        if (isset($this->resolvedBlocks[$name])) {
            return $this->resolvedBlocks[$name];
        }
        return null;
    }
}
