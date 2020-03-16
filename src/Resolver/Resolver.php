<?php

namespace ricwein\Templater\Resolver;

use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\CoreOperators;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\Result;
use ricwein\Tokenizer\Result\ResultBlock;
use ricwein\Tokenizer\Result\ResultSymbol;
use ricwein\Tokenizer\Result\ResultSymbolBase;
use UnexpectedValueException;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Tokenizer;

class Resolver
{
    private array $bindings;

    /**
     * @var BaseFunction[]
     */
    private array $functions;

    private static ?array $operators = null;

    private Tokenizer $tokenizer;

    /**
     * Resolver constructor.
     * @param array $bindings
     * @param array $functions
     * @throws UnexpectedValueException
     */
    public function __construct(array $bindings = [], array $functions = [])
    {
        $this->bindings = $bindings;
        $this->functions = $functions;

        // core delimiters:
        $tokenDelimiter = [
            new Delimiter('.', false),
            new Delimiter('|', false),
            new Delimiter(',', false),

            new Delimiter(':', true),
            new Delimiter('?', true),
        ];

        // operator delimiters:
        foreach (array_keys(static::getOperators()) as $operator) {
            $tokenDelimiter[] = new Delimiter($operator, true);
        }

        $this->tokenizer = new Tokenizer($tokenDelimiter, [
            new Block('[', ']', true),
            new Block('(', ')', true),
            new Block('{', '}', true),
            new Block('\'', null, false),
            new Block('"', null, false),
        ]);
    }

    private static function getOperator(?string $operator): ?callable
    {
        if ($operator === null) {
            return null;
        }

        $operators = static::getOperators();
        if (!isset($operators[$operator])) {
            return null;
        }

        return $operators[$operator];
    }

    private static function getOperators(): array
    {
        if (static::$operators !== null) {
            return static::$operators;
        }

        static::$operators = (new CoreOperators())->get();

        return static::$operators;
    }

    /**
     * Casts input (string) $parameter to real data-type.
     * Resolves bindings if required.
     * @param string $parameter
     * @return mixed
     * @throws RuntimeException
     */
    public function resolve(string $parameter)
    {
        $tokens = $this->tokenizer->tokenize($parameter);
        return $this->resolveTokenized($tokens);
    }

    /**
     * Takes a List of Tokens, solves depth-first blocks
     * and returns an real-datatype result.
     * @param Result $tokens
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveTokenized(Result $tokens)
    {
        if ($tokens->isEmpty()) {
            return '';
        }

        return $this->resolveSymbols($tokens->symbols())->value();
    }

    /**
     * Takes a List of Tokens, solves depth-first blocks
     * and returns a real-datatype result.
     * @param ResultSymbolBase[] $symbols
     * @return Symbol
     * @throws RuntimeException
     */
    private function resolveSymbols(array $symbols): Symbol
    {
        // Split symbols into separated contexts:
        $current = ['delimiter' => null, 'context' => []];
        $results = [];
        foreach ($symbols as $symbol) {

            // Stays in context:
            if (!$symbol->isContextSwitching()) {
                $current['context'][] = $symbol;
                continue;
            }

            // Detected context-switch! Resolve previous context:
            $previousValue = null;
            if (null !== $previousKey = array_key_last($results)) {
                $previousValue = $results[$previousKey]['value']->value();
            }
            $results[] = ['value' => $this->resolveContextSymbols($current['context'], false, $previousValue), 'delimiter' => $current['delimiter']];

            // Start new context:
            $current = ['delimiter' => $symbol->delimiter(), 'context' => [$symbol]];
        }

        // resolve remaining open context
        $previousValue = null;
        if (null !== $previousKey = array_key_last($results)) {
            $previousValue = $results[$previousKey]['value']->value();
        }
        $results[] = ['value' => $this->resolveContextSymbols($current['context'], true, $previousValue), 'delimiter' => $current['delimiter']];

        return $this->resolveContextResults($results);
    }

    /**
     * @param array $results
     * @return Symbol
     * @throws RuntimeException
     */
    private function resolveContextResults(array $results): Symbol
    {
        $first = array_shift($results);

        /** @var Symbol $value */
        $value = $first['value'];

        if (count($results) < 1) {
            return $value;
        }

        /** @var Symbol|null $condition */
        $condition = null;

        /** @var array $ifBranch */
        $ifBranch = [];

        /** @var array $elseBranch */
        $elseBranch = [];

        foreach ($results as $current) {

            /** @var Delimiter|null $rhsDelimiter */
            $rhsDelimiter = $current['delimiter'];

            /** @var Symbol $rhsValue */
            $rhsValue = $current['value'];

            if (count($ifBranch) > 0) {
                if ($rhsDelimiter->is(':') || count($elseBranch) > 0) {
                    $elseBranch[] = $current;
                } else {
                    $ifBranch[] = $current;
                }
                continue;
            }

            if ($rhsDelimiter !== null && $rhsDelimiter->is('?')) {

                // Split remaining results into branches and resolve them
                $condition = $value;
                $ifBranch[] = $current;

            } else if (null !== $operator = static::getOperator($rhsDelimiter)) {

                // resolve operators
                $value = $operator($value, $rhsValue);

            } else {

                throw new RuntimeException("Unsupported context-switching delimiter: {$rhsDelimiter}", 500);

            }
        }

        if (count($ifBranch) <= 0) {
            return $value;
        }

        if ($condition->value()) {
            return $this->resolveContextResults($ifBranch);
        }

        if (count($elseBranch) > 0) {
            return $this->resolveContextResults($elseBranch);
        }

        return new Symbol(null, false, Symbol::TYPE_NULL);
    }

    /**
     * Expects list of Symbols/SymbolBlocks which belong to the same context,
     * e.g. same part of an operator statement or belong to the same keypath iteration
     * @param ResultSymbolBase[] $symbols
     * @param bool $isLastContext
     * @param mixed|null $stateValue
     * @return Symbol
     * @throws RuntimeException
     */
    private function resolveContextSymbols(array $symbols, bool $isLastContext, $stateValue = null): Symbol
    {
        if (count($symbols) < 1) {
            return new Symbol(null, Symbol::TYPE_NULL);
        }

        // the first symbol must always have a null-delimiter
        $symbols[0] = $symbols[0]->setDelimiter(null);

        // float as special edge case since they contain a single . char
        // but must still be interpreted as a single symbol, e.g. 3.14
        if (SymbolHelper::isFloat($symbols)) {
            $numberString = sprintf('%s.%s', $symbols[0]->symbol(), $symbols[1]->symbol());
            return new Symbol((float)$numberString, false, Symbol::TYPE_FLOAT);
        }

        /** @var Symbol|null $lastSymbol */
        $lastSymbol = null;
        /** @var Symbol $value */
        $value = new Symbol(null, Symbol::TYPE_NULL);
        $keyPathFinder = new KeypathFinder($this->bindings);

        // iterate through list of symbols
        // resolve depth-first
        // respects different symbol delimiters like:
        //   [keypaths, function-calls, conditions, operations]
        foreach ($symbols as $symbol) {
            // resolve the current symbol

            /** @var Symbol[] $resolvedSymbol */
            if ($symbol instanceof ResultBlock) {
                $resolvedSymbols = $this->resolveSymbolBlock($symbol, $value->value());
            } else if ($symbol instanceof ResultSymbol) {
                $resolvedSymbols = $this->resolveSymbol($symbol, $value->value());
            } else {
                throw new RuntimeException(sprintf("FATAL: Invalid Symbol type: %s.", get_class($symbol)), 500);
            }

            foreach ($resolvedSymbols as $resolvedSymbol) {

                // check if the last symbol is part of a bindings keypath
                if (
                    !$resolvedSymbol->interruptKeyPath()
                    && $resolvedSymbol->is(Symbol::ANY_KEYPATH_PART)
                    && ($symbol->delimiter() === null || $symbol->delimiter()->is('.'))
                ) {

                    // overload current keypath bindings with the previous symbol, if it was an inline array and
                    // the current symbol is a keypath-part (.part) which points into this array
                    if ($lastSymbol !== null && $lastSymbol->is(Symbol::ANY_ACCESSIBLE) && ($symbol->delimiter() === null || $symbol->delimiter()->is('.'))) {
                        $keyPathFinder = new KeypathFinder($lastSymbol->value());
                    }

                    if ($keyPathFinder->next($resolvedSymbol->value(true))) {

                        $value = new Symbol($keyPathFinder->get(), $resolvedSymbol->interruptKeyPath());

                    } elseif ($symbol->delimiter() === null && $resolvedSymbol->is(Symbol::ANY_DEFINABLE)) {

                        $value = clone $resolvedSymbol;

                    } else if ($symbol !== $symbols[array_key_last($symbols)] || !$isLastContext) {

                        $value = new Symbol(null, Symbol::TYPE_NULL);

                    } else if ($resolvedSymbol->is(Symbol::TYPE_STRING) && null !== $function = $this->getFunction($resolvedSymbol->value())) {

                        $value = new Symbol($function->call([$stateValue]), false);

                    } else {
                        throw new RuntimeException(sprintf(
                            "Unable to resolve variable path: %s. Unknown key: %s in path: %s",
                            new Result($symbols),
                            $symbol,
                            implode('.', $keyPathFinder->getPath())
                        ), 500);
                    }

                } else {

                    $keyPathFinder->reset();
                    $value = clone $resolvedSymbol;

                }

                $lastSymbol = $resolvedSymbol;
            }

        }

        return $value;
    }


    /**
     * @param ResultSymbol $symbol
     * @param $stateVar
     * @return Symbol[]
     * @throws RuntimeException
     */
    private function resolveSymbol(ResultSymbol $symbol, $stateVar): array
    {
        if ($symbol->delimiter() === null || !$symbol->delimiter()->is('|')) {
            return [new Symbol($symbol->asGuessedType(), false)];
        }

        $functionName = trim($symbol->symbol());
        $function = $this->getFunction($functionName);
        if ($function === null) {
            throw new RuntimeException("Call to unknown function: {$functionName}() in '{$symbol}'", 500);
        }

        return [new Symbol($function->call([$stateVar]), true)];
    }

    /**
     * @param ResultBlock $block
     * @param mixed $stateVar
     * @return Symbol[]
     * @throws RuntimeException
     */
    private function resolveSymbolBlock(ResultBlock $block, $stateVar): array
    {
        switch (true) {

            // "test"
            case SymbolHelper::isString($block):
                return [new Symbol($this->resolveSymbolStringBlock($block), true, Symbol::TYPE_STRING)];

            // test()
            case SymbolHelper::isDirectUserFunctionCall($block):
                return [new Symbol($this->resolveSymbolFunctionBlock($block, false), true)];

            // value | test()
            case SymbolHelper::isChainedUserFunctionCall($block):
                return [new Symbol($this->resolveSymbolFunctionBlock($block, true, $stateVar), true)];

            // test[0] || [some, things][0]
            case SymbolHelper::isArrayAccess($block, $stateVar):
                $blockResult = new Symbol($this->resolveSymbols($block->symbols())->value(), false);
                return $block->prefix() !== null ? [new Symbol($block->prefix(), false), $blockResult] : [$blockResult];

            // ( first.name )
            case SymbolHelper::isPriorityBrace($block):
                return [$this->resolveSymbols($block->symbols())];

            // test.exec()
            case SymbolHelper::isMethodCall($block):
                return [new Symbol($this->resolveMethodCall($block, $stateVar), true)];

            // [test.value, 1, 'string']
            case SymbolHelper::isInlineArray($block):
                return [new Symbol($this->buildArrayFromBlockToken($block), true, Symbol::TYPE_ARRAY)];

            // {'key': test.value }
            case SymbolHelper::isInlineAssoc($block):
                return [new Symbol($this->buildAssocFromBlockToken($block), true, Symbol::TYPE_ARRAY)];
        }

        throw new RuntimeException(sprintf("Unsupported Block-Type: %s%s%s%s in %s",
            $block->delimiter(),
            $block->prefix(),
            $block->block()->open(),
            $block->block()->close(),
            $block,
        ), 500);
    }

    /**
     * @param ResultBlock $block
     * @return string
     * @throws RuntimeException
     */
    private function resolveSymbolStringBlock(ResultBlock $block): string
    {
        $retVal = '';
        foreach ($block->symbols() as $symbol) {
            if ($symbol instanceof ResultBlock) {
                throw new RuntimeException("String-token blocks should not be parsed, but a sub-block was return.", 500);
            }
            $retVal .= $symbol->symbol();
        }
        return $retVal;
    }

    /**
     * @param ResultBlock $block
     * @param $class
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveMethodCall(ResultBlock $block, $class)
    {
        if (!is_object($class)) {
            throw new RuntimeException(sprintf
            ("Tried to call method: %s(), but unable on object of type: %s",
                $block->prefix(),
                is_object($class) ? sprintf('class (%s)', get_class($class)) : gettype($class)
            ), 500);
        }

        if (!method_exists($class, $block->prefix())) {
            throw new RuntimeException(sprintf("Call to unknown method: %s::%s()", get_class($class), $block->prefix()), 500);
        }

        $parameters = $this->resolveParameterList($block->symbols());

        return call_user_func_array([$class, $block->prefix()], $parameters);
    }

    /**
     * @param array $parameterSymbols
     * @return array
     * @throws RuntimeException
     */
    private function resolveParameterList(array $parameterSymbols): array
    {
        $parameters = [];
        $unresolvedSymbols = [];
        foreach ($parameterSymbols as $key => $symbol) {
            if ($symbol->delimiter() !== null && $symbol->delimiter()->is(',')) {
                $param = $this->resolveSymbols($unresolvedSymbols)->value();
                if (is_string($param)) {
                    $param = stripslashes($param);
                }
                $parameters[] = $param;
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if (!empty($unresolvedSymbols)) {
            $param = $this->resolveSymbols($unresolvedSymbols)->value();
            if (is_string($param)) {
                $param = stripslashes($param);
            }
            $parameters[] = $param;
        }

        return $parameters;
    }

    /**
     * @param ResultBlock $block
     * @param bool $preprendValue
     * @param null|mixed $value
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveSymbolFunctionBlock(ResultBlock $block, bool $preprendValue, $value = null)
    {
        $function = $this->getFunction($block->prefix());
        if ($function === null) {
            throw new RuntimeException("Call to unknown function: {$block->prefix()}()", 500);
        }

        $parameters = [];
        if ($preprendValue) {
            $parameters[] = $value;
        }

        $parameters = array_merge(
            $parameters,
            $this->resolveParameterList($block->symbols())
        );

        return $function->call($parameters);
    }

    private function getFunction(string $name): ?BaseFunction
    {
        if (isset($this->functions[$name])) {
            return $this->functions[$name];
        }

        foreach ($this->functions as $function) {
            if ($function->getShortName() === $name || $function->getName() === $name) {
                return $function;
            }
        }

        return null;
    }

    /**
     * @param ResultBlock $block
     * @return array
     * @throws RuntimeException
     */
    private function buildArrayFromBlockToken(ResultBlock $block): array
    {
        return $this->resolveParameterList($block->symbols());
    }

    /**
     * @param ResultBlock $block
     * @return array
     * @throws RuntimeException
     */
    private function buildAssocFromBlockToken(ResultBlock $block): array
    {
        $result = [];
        $key = null;
        $unresolvedSymbols = [];

        foreach ($block->symbols() as $symbol) {

            if ($symbol->delimiter() !== null && $symbol->delimiter()->is(':')) {
                if ($key !== null) {
                    throw new RuntimeException("Found unexpected delimiter '{$symbol->delimiter()}' in inline assoc definition: {$block}", 500);
                }

                $key = $this->resolveSymbols($unresolvedSymbols)->value();
                $unresolvedSymbols = [];
            } else if ($symbol->delimiter() !== null && $symbol->delimiter()->is(',')) {
                if ($key === null) {
                    throw new RuntimeException("Found unexpected delimiter '{$symbol->delimiter()}' in inline assoc definition: {$block}", 500);
                }

                $result[$key] = $this->resolveContextSymbols($unresolvedSymbols, false)->value();
                $key = null;
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if ($key === null) {
            throw new RuntimeException("Unexpected end of inline assoc definition: {$block}", 500);
        }

        $result[$key] = $this->resolveContextSymbols($unresolvedSymbols, true)->value();

        return $result;
    }
}
