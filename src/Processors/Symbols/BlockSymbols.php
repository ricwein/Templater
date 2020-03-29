<?php

namespace ricwein\Templater\Processors\Symbols;

use ricwein\Templater\Processors\Processor;
use ricwein\Tokenizer\Result\BaseToken;

class BlockSymbols extends HeadOnlySymbols
{
    /**
     * @var Processor[]|BaseToken[]
     */
    public array $content;

    /**
     * @inheritDoc
     * @param Processor[]|BaseToken[] $content
     */
    public function __construct(?string $type, array $headTokens, array $content = [])
    {
        parent::__construct($type, $headTokens);
        $this->content = $content;
    }
}
