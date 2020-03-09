<?php

namespace ricwein\Templater\Resolver;

use ricwein\Templater\Engine\BaseFunction;
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

        $tokenDelimiter = [
            new Delimiter('.'),
            new Delimiter('|'),
            new Delimiter(','),
            new Delimiter(':'),
        ];

        foreach (array_keys(static::getOperators()) as $operator) {
            $tokenDelimiter[] = new Delimiter($operator);
        }

        $this->tokenizer = new Tokenizer($tokenDelimiter, [
            new Block('[', ']', true),
            new Block('(', ')', true),
            new Block('{', '}', true),
            new Block('\'', null, false),
            new Block('"', null, false),
        ]);
    }

    private static function getOperators(): array
    {
        if (static::$operators !== null) {
            return static::$operators;
        }

        static::$operators = [
            '>' => function ($lhs, $rhs): bool {
                return $lhs > $rhs;
            },
            '<' => function ($lhs, $rhs): bool {
                return $lhs < $rhs;
            },
            '==' => function ($lhs, $rhs): bool {
                return $lhs === $rhs;
            },
            '!=' => function ($lhs, $rhs): bool {
                return $lhs !== $rhs;
            },
            '>=' => function ($lhs, $rhs): bool {
                return $lhs >= $rhs;
            },
            '<=' => function ($lhs, $rhs): bool {
                return $lhs <= $rhs;
            },
            ' in ' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return in_array($lhs, $rhs, true);
                    case is_string($lhs) && is_string($rhs):
                        return strpos($rhs, $lhs) !== false;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            ' not in ' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return !in_array($lhs, $rhs, true);
                    case is_string($lhs) && is_string($rhs):
                        return strpos($rhs, $lhs) === false;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            ' starts with ' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return $lhs === $rhs[array_key_first($rhs)];
                    case is_string($lhs) && is_string($rhs):
                        return strpos($lhs, $rhs) === 0;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            ' ends with ' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return $lhs === $rhs[array_key_last($rhs)];
                    case is_string($lhs) && is_string($rhs):
                        return strrpos($lhs, $rhs) === (strlen($lhs) - strlen($rhs));
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },

            '??' => function ($lhs, $rhs) {
                return $lhs !== null ? $lhs : $rhs;
            },
            ' and ' => function ($lhs, $rhs): bool {
                return $lhs && $rhs;
            },
            ' && ' => function ($lhs, $rhs): bool {
                return $lhs && $rhs;
            },
            ' or ' => function ($lhs, $rhs): bool {
                return $lhs || $rhs;
            },
            ' || ' => function ($lhs, $rhs): bool {
                return $lhs || $rhs;
            },
            ' xor ' => function ($lhs, $rhs): bool {
                return $lhs xor $rhs;
            },

            '~' => function (string $lhs, string $rhs): string {
                return $lhs . $rhs;
            },
            '+' => function ($lhs, $rhs) {
                return $lhs + $rhs;
            },
            '-' => function ($lhs, $rhs) {
                return $lhs - $rhs;
            },
            '*' => function ($lhs, $rhs) {
                return $lhs * $rhs;
            },
            '/' => function ($lhs, $rhs) {
                return $lhs / $rhs;
            },
        ];

        return static::$operators;
    }

    /**
     * @param string $operator
     * @param $lhs
     * @param $rhs
     * @return RuntimeException
     */
    private static function datatypeException(string $operator, $lhs, $rhs): RuntimeException
    {
        return new RuntimeException(
            sprintf(
                "Invalid datatypes for '%s' operator. Parameters are lhs: %s, lhs: %s ",
                $operator,
                is_object($lhs) ? sprintf('class(%s)', get_class($lhs)) : gettype($lhs),
                is_object($rhs) ? sprintf('class(%s)', get_class($rhs)) : gettype($rhs),
            ),
            500
        );
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

        return $this->resolveSymbols($tokens->symbols());
    }

    /**
     * Takes a List of Tokens, solves depth-first blocks
     * and returns a real-datatype result.
     * @param ResultSymbolBase[] $symbols
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveSymbols(array $symbols)
    {
        // float as special edge case since they contain a single . char
        // but must still be interpreted as a single symbol, e.g. 3.14
        if (
            count($symbols) === 2
            && $symbols[0] instanceof ResultSymbol && is_numeric($symbols[0]->symbol()) && strlen($symbols[0]->symbol()) === strlen((string)(int)$symbols[0]->symbol())
            && $symbols[1] instanceof ResultSymbol && is_numeric($symbols[1]->symbol()) && strlen($symbols[1]->symbol()) === strlen((string)(int)$symbols[1]->symbol())
            && $symbols[1]->delimiter() !== null && $symbols[1]->delimiter()->is('.')
        ) {
            $numberString = sprintf('%s.%s', $symbols[0]->symbol(), $symbols[1]->symbol());
            return (float)$numberString;
        }

        /** @var Symbol|null $lastSymbol */
        $lastSymbol = null;
        $value = null;
        $keyPathFinder = new KeypathFinder($this->bindings);

        // iterate through list of symbols
        // resolve depth-first
        // respects different symbol delimiters like:
        //   keypaths, function-calls, conditions or operations
        foreach ($symbols as $symbol) {

            // resolve the current symbol

            /** @var Symbol $resolvedSymbol */
            $resolvedSymbol = null;

            if ($symbol instanceof ResultBlock) {
                $resolvedSymbol = $this->resolveSymbolBlock($symbol, $value);
            } else if ($symbol instanceof ResultSymbol) {
                $resolvedSymbol = new Symbol($symbol->asGuessedType());
            }

            // check if the last symbol is part of a bindings keypath
            if (
                ($resolvedSymbol->is(Symbol::TYPE_VARIABLE) && ($symbol->delimiter() === null || $symbol->delimiter()->is('.'))) ||
                (in_array($resolvedSymbol->type(), [Symbol::TYPE_FLOAT, Symbol::TYPE_INT], true) && $symbol->delimiter() !== null && $symbol->delimiter()->is('.'))
            ) {

                // overload current keypath bindings with the previous symbol, if it was an inline array and
                // the current symbol is a keypath-part (.part) which points into this array
                if ($lastSymbol !== null && in_array($lastSymbol->type(), [Symbol::TYPE_ARRAY, Symbol::TYPE_OBJECT], true) && $symbol->delimiter()->is('.')) {
                    $keyPathFinder = new KeypathFinder($lastSymbol->value());
                }

                $keyPathFinder->next($resolvedSymbol->value());
                $value = $keyPathFinder->get();
            } else {
                $keyPathFinder->reset();
                $value = $resolvedSymbol->value();
            }


            $lastSymbol = $resolvedSymbol;
        }

        return $value;
    }

    /**
     * @param ResultBlock $block
     * @param mixed $stateVar
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveSymbolBlock(ResultBlock $block, $stateVar): Symbol
    {
        switch (true) {
            // "test"
            case SymbolHelper::isString($block):
                return new Symbol($this->resolveSymbolStringBlock($block), Symbol::TYPE_STRING);

            // test()
            case SymbolHelper::isDirectUserFunctionCall($block):
                return new Symbol($this->resolveSymbolFunctionBlock($block, false));

            // value | test()
            case SymbolHelper::isChainedUserFunctionCall($block):
                return new Symbol($this->resolveSymbolFunctionBlock($block, true, $stateVar));

            // test.( first.name )
            case SymbolHelper::isPriorityBrace($block):
                return new Symbol($this->resolveSymbols($block->symbols()));

            // test.exec()
            case SymbolHelper::isMethodCall($block):
                return new Symbol($this->resolveMethodCall($block, $stateVar));

            // [test.value, 1, 'string']
            case SymbolHelper::isInlineArray($block):
                return new Symbol($this->buildArrayFromBlockToken($block), Symbol::TYPE_ARRAY);

            // {'key': test.value }
            case SymbolHelper::isInlineAssoc($block):
                return new Symbol($this->buildAssocFromBlockToken($block), Symbol::TYPE_ARRAY);
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
                $parameters[] = $this->resolveSymbols($unresolvedSymbols);
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if (!empty($unresolvedSymbols)) {
            $parameters[] = $this->resolveSymbols($unresolvedSymbols);
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
            throw new RuntimeException("Call to unknown function '{$block->prefix()}'", 500);
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

                $key = $this->resolveSymbols($unresolvedSymbols);
                $unresolvedSymbols = [];
            } else if ($symbol->delimiter() !== null && $symbol->delimiter()->is(',')) {
                if ($key === null) {
                    throw new RuntimeException("Found unexpected delimiter '{$symbol->delimiter()}' in inline assoc definition: {$block}", 500);
                }

                $result[$key] = $this->resolveSymbols($unresolvedSymbols);
                $key = null;
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if ($key === null) {
            throw new RuntimeException("Unexpected end of inline assoc definition: {$block}", 500);
        }

        $result[$key] = $this->resolveSymbols($unresolvedSymbols);

        return $result;
    }
}