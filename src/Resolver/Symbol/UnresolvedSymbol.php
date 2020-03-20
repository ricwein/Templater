<?php

namespace ricwein\Templater\Resolver\Symbol;

use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Resolver\Resolver;
use ricwein\Tokenizer\Result\ResultSymbolBase;

class UnresolvedSymbol extends Symbol
{
    /**
     * @todo change type
     * @var ResultSymbolBase[]
     */
    private array $symbols = [];

    /**
     * @var Symbol|null
     */
    private ?Symbol $resolvedCache = null;

    /**
     * @var Symbol|null
     */
    private ?Symbol $predecessorSymbol = null;

    private Resolver $resolver;

    /**
     * @inheritDoc
     * @param ResultSymbolBase[] $symbols
     */
    public function __construct(array $symbols, Resolver $resolver)
    {
        $this->symbols = $symbols;
        $this->resolver = $resolver;
    }

    /**
     * @param Symbol|null $predecessorSymbol
     * @return $this
     */
    public function withPredecessorSymbol(?Symbol $predecessorSymbol = null): self
    {
        $this->predecessorSymbol = $predecessorSymbol;
        $this->resolvedCache = null;
        return $this;
    }

    /**
     * @return Symbol
     * @throws RuntimeException
     */
    public function resolved(): Symbol
    {
        if ($this->resolvedCache) {
            return $this->resolvedCache;
        }

        $symbol = $this->resolver->resolveContextSymbols($this->symbols, $this->predecessorSymbol);
        $this->resolvedCache = $symbol;

        return $symbol;
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function value(bool $trimmed = false)
    {
        return $this->resolved()->value($trimmed);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function interruptKeyPath(): bool
    {
        return $this->resolved()->interruptKeyPath();
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function type(): string
    {
        return $this->resolved()->type();
    }
}
