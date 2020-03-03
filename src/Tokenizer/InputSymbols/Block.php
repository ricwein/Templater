<?php


namespace ricwein\Templater\Tokenizer\InputSymbols;


class Block
{
    private Delimiter $symbolOpen;
    private Delimiter $symbolClose;

    private bool $shouldTokenizeContent;

    /**
     * Block constructor.
     * @param string $symbolOpen
     * @param string|null $symbolClose
     * @param bool $shouldTokenizeContent
     */
    public function __construct(string $symbolOpen, ?string $symbolClose, bool $shouldTokenizeContent)
    {
        $this->symbolOpen = new Delimiter($symbolOpen);

        if ($symbolClose !== null) {
            $this->symbolClose = new Delimiter($symbolClose);
        } else {
            $this->symbolClose = $this->symbolOpen;
        }

        $this->shouldTokenizeContent = $shouldTokenizeContent;
    }

    /**
     * @return Delimiter
     */
    public function open(): Delimiter
    {
        return $this->symbolOpen;
    }

    /**
     * @return Delimiter
     */
    public function close(): Delimiter
    {
        return $this->symbolClose;
    }

    /**
     * @return bool
     */
    public function shouldTokenizeContent(): bool
    {
        return $this->shouldTokenizeContent;
    }

    public function __toString()
    {
        return "{$this->open()}{$this->close()}";
    }
}
