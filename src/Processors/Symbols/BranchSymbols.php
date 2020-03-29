<?php

namespace ricwein\Templater\Processors\Symbols;

class BranchSymbols extends BaseSymbols
{
    /**
     * @var BlockSymbols[]
     */
    private array $branches;

    /**
     * BranchSymbols constructor.
     * @param BlockSymbols[] $branches
     */
    public function __construct(array $branches)
    {
        $this->branches = $branches;
    }

    /**
     * @return BlockSymbols[]
     */
    public function branches(): array
    {
        return $this->branches;
    }

    public function branch(int $index): ?BlockSymbols
    {
        if (isset($this->branches[$index])) {
            return $this->branches[$index];
        }
        return null;
    }

}
