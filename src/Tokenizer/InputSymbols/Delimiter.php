<?php


namespace ricwein\Templater\Tokenizer\InputSymbols;


class Delimiter
{
    private string $symbol;
    private int $length;

    /**
     * Delimiter constructor.
     * @param string $symbol
     */
    public function __construct(string $symbol)
    {
        $this->symbol = $symbol;
        $this->length = strlen($symbol);
    }

    /**
     * @return string
     */
    public function symbol(): string
    {
        return $this->symbol;
    }

    /**
     * @return int
     */
    public function length(): int
    {
        return $this->length;
    }

    public function __toString()
    {
        return $this->symbol();
    }
}
