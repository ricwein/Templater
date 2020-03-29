<?php


namespace ricwein\Templater\Processors\Symbols;


use ricwein\Tokenizer\Result\BaseToken;

class HeadOnlySymbols extends BaseSymbols
{
    /**
     * @var BaseToken[]
     */
    private array $headTokens;
    private ?string $type = null;

    /**
     * @param string|null $type
     * @param BaseToken[] $headTokens
     */
    public function __construct(?string $type, array $headTokens)
    {
        $this->headTokens = $headTokens;
        $this->type = strtolower(trim($type));
    }

    /**
     * @return BaseToken[]
     */
    public function headTokens(): array
    {
        return $this->headTokens;
    }

    public function type(): ?string
    {
        return $this->type;
    }
}
