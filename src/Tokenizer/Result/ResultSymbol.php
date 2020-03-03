<?php

namespace ricwein\Templater\Tokenizer\Result;

use ricwein\Templater\Tokenizer\InputSymbols\Delimiter;

class ResultSymbol
{
    private string $symbol;
    private ?Delimiter $delimiter;

    /**
     * ResultSymbol constructor.
     * @param string $symbol
     * @param Delimiter|null $delimiter
     */
    public function __construct(string $symbol, ?Delimiter $delimiter)
    {
        $this->symbol = $symbol;
        $this->delimiter = $delimiter;
    }

    /**
     * @return string
     */
    public function symbol(): string
    {
        return $this->symbol;
    }

    /**
     * @return Delimiter|null
     */
    public function delimiter(): ?Delimiter
    {
        return $this->delimiter;
    }

    /**
     * Helpful for debugging
     * @return string
     */
    public function __toString(): string
    {
        return <<<EOD
        >>>> SYMBOL <<<<
        Delimiter: {$this->delimiter}
        Symbol: {$this->symbol}
        EOD;
    }

}
