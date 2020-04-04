<?php

namespace ricwein\Templater\Resolver;

use UnexpectedValueException;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\CoreOperators;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Resolver\Symbol\ResolvedSymbol;
use ricwein\Templater\Resolver\Symbol\Symbol;
use ricwein\Templater\Resolver\Symbol\UnresolvedSymbol;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Tokenizer;

class ExpressionResolver
{
    private const IGNORE_VAR = 0b00;
    private const APPEND_VAR = 0b01;
    private const PREPEND_VAR = 0b10;

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
     * @param int $startLine
     * @return mixed
     * @throws RuntimeException
     */
    public function resolve(string $parameter, int $startLine = 1)
    {
        $tokens = $this->tokenizer->tokenize($parameter, $startLine);
        return $this->resolveTokenized($tokens);
    }

    /**
     * Takes a List of Tokens, solves depth-first blocks
     * and returns an real-datatype result.
     * @param TokenStream $stream
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveTokenized(TokenStream $stream)
    {
        if ($stream->isEmpty()) {
            return '';
        }

        return $this->resolveTokens($stream->tokens())->value();
    }

    /**
     * Takes a List of Tokens, solves depth-first blocks
     * and returns a real-datatype result.
     * @param BaseToken[] $symbols
     * @return Symbol
     * @throws RuntimeException
     */
    private function resolveTokens(array $symbols): Symbol
    {
        // Split symbols into separated contexts:
        $current = ['delimiter' => null, 'context' => []];

        /** @var array[] $results */
        $results = [];
        foreach ($symbols as $symbol) {

            // Stays in context:
            if (!$symbol->isContextSwitching()) {
                $current['context'][] = $symbol;
                continue;
            }

            // Detected context-switch! Resolve previous context:
            $contextSymbols = new UnresolvedSymbol($current['context'], $this);
            if (null !== $previousKey = array_key_last($results)) {
                $contextSymbols->withPredecessorSymbol($results[$previousKey]['symbol']);
            }
            $results[] = ['symbol' => $contextSymbols, 'delimiter' => $current['delimiter']];

            // Start new context:
            $current = ['delimiter' => $symbol->delimiter(), 'context' => [$symbol]];
        }

        // resolve remaining open context
        $contextSymbols = new UnresolvedSymbol($current['context'], $this);
        if (null !== $previousKey = array_key_last($results)) {
            $contextSymbols->withPredecessorSymbol($results[$previousKey]['symbol']);
        }
        $results[] = ['symbol' => $contextSymbols, 'delimiter' => $current['delimiter']];

        return $this->resolveContextResults($results);
    }

    /**
     * @param array $results 'symbol' => UnresolvedSymbol,'delimiter' => Delimiter
     * @return ResolvedSymbol
     * @throws RuntimeException
     */
    private function resolveContextResults(array $results): Symbol
    {
        $first = array_shift($results);

        /** @var Symbol $value */
        $value = $first['symbol'];

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
            $rhsValue = $current['symbol'];

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

        return new ResolvedSymbol(null, false, ResolvedSymbol::TYPE_NULL);
    }

    /**
     * Expects list of Symbols/SymbolBlocks which belong to the same context,
     * e.g. same part of an operator statement or belong to the same keypath iteration
     * @param BaseToken[] $symbols
     * @param Symbol $predecessorSymbol
     * @return ResolvedSymbol
     * @throws RuntimeException
     * @internal
     */
    public function resolveContextTokens(array $symbols, ?Symbol $predecessorSymbol = null): Symbol
    {
        if (count($symbols) < 1) {
            return new ResolvedSymbol(null, false, ResolvedSymbol::TYPE_NULL);
        }

        // the first symbol must always have a null-delimiter
        $symbols[0] = $symbols[0]->setDelimiter(null);

        // float as special edge case since they contain a single . char
        // but must still be interpreted as a single symbol, e.g. 3.14
        if (SymbolHelper::isFloat($symbols)) {
            $numberString = sprintf('%s.%s', $symbols[0]->token(), $symbols[1]->token());
            return new ResolvedSymbol((float)$numberString, false, ResolvedSymbol::TYPE_FLOAT);
        }

        /** @var Symbol|null $lastSymbol */
        $lastSymbol = null;
        $value = ($predecessorSymbol !== null) ? $predecessorSymbol : new ResolvedSymbol(null, false, ResolvedSymbol::TYPE_NULL);
        $keyPathFinder = new KeypathFinder($this->bindings);

        // iterate through list of symbols
        // resolve depth-first
        // respects different symbol delimiters like:
        //   [keypaths, function-calls, conditions, operations]
        foreach ($symbols as $symbol) {

            // resolve the current symbol

            /** @var ResolvedSymbol[] $resolvedSymbol */
            if ($symbol instanceof BlockToken) {
                $resolvedSymbols = $this->resolveSymbolBlock($symbol, $value);
            } else if ($symbol instanceof Token) {
                $resolvedSymbols = [$this->resolveToken($symbol, $value)];
            } else {
                throw new RuntimeException(sprintf("FATAL: Invalid Symbol type: %s.", get_class($symbol)), 500);
            }

            foreach ($resolvedSymbols as $resolvedSymbol) {

                // check if the last symbol is part of a bindings keypath
                if (
                    !$resolvedSymbol->interruptKeyPath()
                    && $resolvedSymbol->is(ResolvedSymbol::ANY_KEYPATH_PART)
                    && ($symbol->delimiter() === null || $symbol->delimiter()->is('.'))
                ) {

                    // overload current keypath bindings with the previous symbol, if it was an inline array and
                    // the current symbol is a keypath-part (.part) which points into this array
                    if ($lastSymbol !== null && $lastSymbol->is(ResolvedSymbol::ANY_ACCESSIBLE) && ($symbol->delimiter() === null || $symbol->delimiter()->is('.'))) {
                        $keyPathFinder = new KeypathFinder($lastSymbol->value());
                    }

                    if ($keyPathFinder->next($resolvedSymbol->value(true))) {

                        $value = new ResolvedSymbol($keyPathFinder->get(), $resolvedSymbol->interruptKeyPath());

                    } else if ($symbol->delimiter() === null && $resolvedSymbol->is(ResolvedSymbol::ANY_DEFINABLE)) {

                        $value = $resolvedSymbol;

                    } else if ($resolvedSymbol->is(ResolvedSymbol::TYPE_STRING) && null !== $function = $this->getFunction($resolvedSymbol->value())) {

                        $value = new ResolvedSymbol($function->call([$value->value()]), false);

                    } else {

                        $value = new ResolvedSymbol(null, false, ResolvedSymbol::TYPE_NULL);

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
     * @param Token $token
     * @param Symbol $value
     * @return ResolvedSymbol
     * @throws RuntimeException
     */
    private function resolveToken(Token $token, Symbol $value): ResolvedSymbol
    {
        if ($token->delimiter() === null || !$token->delimiter()->is('|')) {
            return new ResolvedSymbol($token->asGuessedType(), false);
        }

        $functionName = trim($token->token());
        $function = $this->getFunction($functionName);
        if ($function === null) {
            throw new RuntimeException("Call to unknown function: {$functionName}() in '{$token}'", 500);
        }

        return new ResolvedSymbol($function->call([$value->value()]), true);
    }

    /**
     * @param BlockToken $block
     * @param Symbol $predecessorSymbol
     * @return ResolvedSymbol[]
     * @throws RuntimeException
     */
    private function resolveSymbolBlock(BlockToken $block, Symbol $predecessorSymbol): array
    {
        switch (true) {

            // "test"
            case SymbolHelper::isString($block):
                return [new ResolvedSymbol($this->resolveSymbolStringBlock($block), true, ResolvedSymbol::TYPE_STRING)];

            // test()
            case SymbolHelper::isDirectUserFunctionCall($block):
                return [new ResolvedSymbol($this->resolveSymbolFunctionBlock($block), true)];

            // value | test()
            case SymbolHelper::isChainedUserFunctionCall($block):
                return [new ResolvedSymbol($this->resolveSymbolFunctionBlock($block, static::PREPEND_VAR, $predecessorSymbol), true)];

            // test[0] || [some, things][0]
            case SymbolHelper::isArrayAccess($block, $predecessorSymbol):
                $blockResult = new ResolvedSymbol($this->resolveTokens($block->tokens())->value(), false);

                if (!$blockResult->is(Symbol::ANY_KEYPATH_PART)) {
                    throw new RuntimeException(
                        sprintf('Invalid key-type for array access. Key must be of type: %s - but got %s instead.', implode('/', Symbol::ANY_KEYPATH_PART), $blockResult->type()),
                        500
                    );
                }

                return $block->prefix() !== null ? [new ResolvedSymbol($block->prefix(), false), $blockResult] : [$blockResult];

            // ( first.name )
            case SymbolHelper::isPriorityBrace($block):
                return [$this->resolveTokens($block->tokens())];

            // test.exec()
            case SymbolHelper::isMethodCall($block):
                return [new ResolvedSymbol($this->resolveMethodCall($block, $predecessorSymbol), true)];

            // [test.value, 1, 'string']
            case SymbolHelper::isInlineArray($block):
                return [new ResolvedSymbol($this->buildArrayFromBlockToken($block), true, ResolvedSymbol::TYPE_ARRAY)];

            // {'key': test.value }
            case SymbolHelper::isInlineAssoc($block):
                return [new ResolvedSymbol($this->buildAssocFromBlockToken($block), true, ResolvedSymbol::TYPE_ARRAY)];
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
     * @param BlockToken $block
     * @return string
     * @throws RuntimeException
     */
    private function resolveSymbolStringBlock(BlockToken $block): string
    {
        $retVal = '';
        foreach ($block->tokens() as $symbol) {
            if ($symbol instanceof BlockToken) {
                throw new RuntimeException("String-token blocks should not be parsed, but a sub-block was return.", 500);
            }
            $retVal .= $symbol->token();
        }
        return $retVal;
    }

    /**
     * @param BlockToken $block
     * @param Symbol $classSymbol
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveMethodCall(BlockToken $block, Symbol $classSymbol)
    {
        $class = $classSymbol->value();
        if (!$classSymbol->is(Symbol::TYPE_OBJECT)) {
            throw new RuntimeException(sprintf
            ("Tried to call method: %s(), but unable on object of type: %s",
                $block->prefix(),
                is_object($class) ? sprintf('class (%s)', get_class($class)) : gettype($class)
            ), 500);
        }

        if (!method_exists($class, $block->prefix())) {
            throw new RuntimeException(sprintf("Call to unknown method: %s::%s()", get_class($class), $block->prefix()), 500);
        }

        $parameters = $this->resolveParameterList($block->tokens());

        return call_user_func_array([$class, $block->prefix()], $parameters);
    }

    /**
     * @param BaseToken[] $parameterSymbols
     * @return array
     * @throws RuntimeException
     */
    private function resolveParameterList(array $parameterSymbols): array
    {
        $parameters = [];
        $unresolvedSymbols = [];
        foreach ($parameterSymbols as $key => $symbol) {
            if ($symbol->delimiter() !== null && $symbol->delimiter()->is(',')) {
                $param = $this->resolveTokens($unresolvedSymbols)->value();
                if (is_string($param)) {
                    $param = stripslashes($param);
                }
                $parameters[] = $param;
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if (!empty($unresolvedSymbols)) {
            $param = $this->resolveTokens($unresolvedSymbols)->value();
            if (is_string($param)) {
                $param = stripslashes($param);
            }
            $parameters[] = $param;
        }

        return $parameters;
    }

    /**
     * @param BlockToken $block
     * @param int $handleVar
     * @param null|Symbol $value
     * @return mixed
     * @throws RuntimeException
     */
    private function resolveSymbolFunctionBlock(BlockToken $block, int $handleVar = self::IGNORE_VAR, ?Symbol $value = null)
    {
        $function = $this->getFunction($block->prefix());
        if ($function === null) {
            throw new RuntimeException("Call to unknown function: {$block->prefix()}()", 500);
        }

        $parameters = $this->resolveParameterList($block->tokens());

        switch ($handleVar) {

            case static::PREPEND_VAR:
                $parameters = array_merge([$value->value()], $parameters);
                break;

            case static::APPEND_VAR:
                $parameters = array_merge($parameters, [$value->value()]);
                break;
        }

        return $function->call($parameters);
    }

    private function getFunction(string $name): ?BaseFunction
    {
        if (isset($this->functions[$name])) {
            return $this->functions[$name];
        }

        foreach ($this->functions as $function) {
            if ($function->getName() === $name) {
                return $function;
            }
        }

        return null;
    }

    /**
     * @param BlockToken $block
     * @return array
     * @throws RuntimeException
     */
    private function buildArrayFromBlockToken(BlockToken $block): array
    {
        return $this->resolveParameterList($block->tokens());
    }

    /**
     * @param BlockToken $block
     * @return array
     * @throws RuntimeException
     */
    private function buildAssocFromBlockToken(BlockToken $block): array
    {
        $result = [];
        $key = null;
        $unresolvedSymbols = [];

        foreach ($block->tokens() as $symbol) {

            if ($symbol->delimiter() !== null && $symbol->delimiter()->is(':')) {
                if ($key !== null) {
                    throw new RuntimeException("Found unexpected delimiter '{$symbol->delimiter()}' in inline assoc definition: {$block}", 500);
                }

                $key = $this->resolveTokens($unresolvedSymbols)->value();
                $unresolvedSymbols = [];
            } else if ($symbol->delimiter() !== null && $symbol->delimiter()->is(',')) {
                if ($key === null) {
                    throw new RuntimeException("Found unexpected delimiter '{$symbol->delimiter()}' in inline assoc definition: {$block}", 500);
                }

                $result[$key] = $this->resolveContextTokens($unresolvedSymbols)->value();
                $key = null;
                $unresolvedSymbols = [];
            }

            $unresolvedSymbols[] = $symbol;
        }

        if ($key === null) {
            throw new RuntimeException("Unexpected end of inline assoc definition: {$block}", 500);
        }

        $result[$key] = $this->resolveContextTokens($unresolvedSymbols)->value();

        return $result;
    }
}
