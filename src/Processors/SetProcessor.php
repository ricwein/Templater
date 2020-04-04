<?php


namespace ricwein\Templater\Processors;


use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;

class SetProcessor extends Processor
{
    private ?string $variableName = null;

    public static function startKeyword(): string
    {
        return 'set';
    }

    protected static function endKeyword(): ?string
    {
        return 'endset';
    }

    protected function requiresEnd(Statement $statement): bool
    {
        $headWords = $statement->remainingTokens();
        $variableName = array_shift($headWords);
        $this->variableName = $variableName->content();

        return count($headWords) <= 0;
    }


    /**
     * @inheritDoc
     * @param Context $context
     * @return array
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function process(Context $context): array
    {
        $value = null;

        if ($this->symbols instanceof BlockSymbols) {

            $value = implode('', $this->templater->resolveSymbols($this->symbols->content, $context));

        } elseif ($this->symbols instanceof HeadOnlySymbols) {

            $headTokens = $this->symbols->headTokens();
            $firstToken = array_shift($headTokens);
            $headString = ltrim(implode('', $headTokens), '= ');
            $value = $context->expressionResolver()->resolve($headString, $firstToken->line());

        } else {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $setBindings = static::chainArray(explode('.', $this->variableName), $value);
        $context->bindings = array_replace_recursive($context->bindings, $setBindings);

        return [];
    }

    /**
     * @param array $keys
     * @param mixed $value
     * @return array
     */
    private static function chainArray(array $keys, $value): array
    {
        $insert = [];
        $key = array_shift($keys);

        // abort condition
        if (count($keys) <= 0) {
            $insert[$key] = $value;
            return $insert;
        }

        // recursive call for infinite deep hierarchic arrays
        $insert[$key] = static::chainArray($keys, $value);
        return $insert;
    }

}
