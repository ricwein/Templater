<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Templater\Processors\Symbols\BranchSymbols;
use ricwein\Tokenizer\Result\Token;

class IfProcessor extends Processor
{
    public static function startKeyword(): string
    {
        return 'if';
    }

    protected static function endKeyword(): ?string
    {
        return 'endif';
    }

    protected static function forkKeywords(): ?array
    {
        return ['elseif', 'else'];
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws \ricwein\Templater\Exceptions\RenderingException
     */
    public function process(Context $context): array
    {
        /** @var BlockSymbols[] $branches */
        if ($this->symbols instanceof BlockSymbols) {
            $branches = [$this->symbols];
        } elseif ($this->symbols instanceof BranchSymbols) {
            $branches = $this->symbols->branches();
        } else {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        foreach ($branches as $branch) {
            switch ($branch->type()) {

                case 'if':
                case 'else if':
                case 'elseif':
                    $conditionString = implode('', array_map(fn(Token $conditionToken): string => $conditionToken, $branch->headTokens()));
                    if ($context->resolver()->resolve($conditionString)) {
                        return $this->templater->resolveSymbols($branch->content, $context);
                    }
                    break;

                case 'else':
                    return $this->templater->resolveSymbols($branch->content, $context);
            }
        }

        return [];
    }
}
