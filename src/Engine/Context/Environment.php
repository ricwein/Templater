<?php

namespace ricwein\Templater\Engine\Context;

use ricwein\Templater\Processors\Processor;
use ricwein\Tokenizer\Result\BaseToken;

class Environment
{
    /**
     * @var array<string, array<int, array<BaseToken|Processor>>>
     */
    private array $blocks = [];

    /**
     * Environment constructor.
     * @param array<string, array<int, array<BaseToken|Processor>>> $blocks
     */
    public function __construct(array $blocks = [])
    {
        $this->blocks = $blocks;
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
     * @param $name
     * @return null|array<int, array<BaseToken|Processor>>
     */
    public function getBlockVersions($name): ?array
    {
        if (!isset($this->blocks[$name])) {
            return null;
        }

        return $this->blocks[$name];
    }

    /**
     * @return array<string, array<int, array<BaseToken|Processor>>>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }
}
