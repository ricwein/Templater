<?php


namespace ricwein\Templater\Engine;


use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Resolver\Symbol\ResolvedSymbol;
use ricwein\Templater\Resolver\Symbol\Symbol;

class CoreOperators
{
    /**
     * @return callable[]
     */
    public function get(): array
    {
        return [
            '===' => [$this, 'equal'],
            '==' => [$this, 'equal'],
            '!==' => [$this, 'notEqual'],
            '!=' => [$this, 'notEqual'],
            '<=>' => [$this, 'compare'],
            '>=' => [$this, 'greaterOrEqual'],
            '<=' => [$this, 'lesserOrEqual'],
            '>' => [$this, 'greater'],
            '<' => [$this, 'lesser'],

            '??' => [$this, 'nullCoalescing'],

            ' in ' => [$this, 'in'],
            ' not in ' => [$this, 'notIn'],
            ' starts with ' => [$this, 'startsWith'],
            ' ends with ' => [$this, 'endsWith'],
            ' matches ' => [$this, 'pregMatch'],
            ' is ' => [$this, 'is'],
            ' is not ' => [$this, 'isNot'],

            ' b-or ' => [$this, 'binaryOr'],
            ' b-xor ' => [$this, 'xor'],
            ' b-and ' => [$this, 'binaryAnd'],
            ' and ' => [$this, 'and'],
            '&&' => [$this, 'and'],
            ' or ' => [$this, 'or'],
            '||' => [$this, 'or'],
            ' xor ' => [$this, 'xor'],

            '..' => [$this, 'range'],
            '...' => [$this, 'range'],
            '~' => [$this, 'concat'],

            '+' => [$this, 'plus'],
            '-' => [$this, 'minus'],
            '*' => [$this, 'multiply'],
            '/' => [$this, 'divide'],
            '%' => [$this, 'mod'],
            '**' => [$this, 'pow'],

            ' instanceof ' => [$this, 'isInstanceof'],
            ' not instanceof ' => [$this, 'isNotInstanceof'],

        ];
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
                "Invalid datatypes for '%s' operator. Parameters are lhs: %s, lhs: %s",
                $operator,
                is_object($lhs) ? sprintf('class(%s)', get_class($lhs)) : gettype($lhs),
                is_object($rhs) ? sprintf('class(%s)', get_class($rhs)) : gettype($rhs),
            ),
            500
        );
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function equal(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() === $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function notEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() !== $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function compare(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() <=> $rhs->value(), false, ResolvedSymbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function greaterOrEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() >= $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function lesserOrEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() <= $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function greater(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() > $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function lesser(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() < $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     */
    public function nullCoalescing(Symbol $lhs, Symbol $rhs): Symbol
    {
        return ($lhs->value() !== null) ? $lhs : $rhs;
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function binaryOr(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() | $rhs->value(), false, ResolvedSymbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function binaryAnd(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() & $rhs->value(), false, ResolvedSymbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function in(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(ResolvedSymbol::TYPE_ARRAY):
                return new ResolvedSymbol(in_array($lhs->value(), $rhs->value(), true), false, ResolvedSymbol::TYPE_BOOL);

            case $lhs->is(ResolvedSymbol::TYPE_STRING) && $rhs->is(ResolvedSymbol::TYPE_STRING):
                return new ResolvedSymbol(strpos($lhs->value(), $rhs->value()) !== false, false, ResolvedSymbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function notIn(Symbol $lhs, Symbol $rhs): Symbol
    {
        $in = $this->in($lhs, $rhs);
        return new ResolvedSymbol(!$in->value(), $in->interruptKeyPath(), $in->type());
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function startsWith(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(ResolvedSymbol::TYPE_ARRAY):
                $rhsArray = (array)$rhs->value();
                return new ResolvedSymbol($lhs->value() === $rhsArray[array_key_first($rhsArray)], false, ResolvedSymbol::TYPE_BOOL);

            case $lhs->is(ResolvedSymbol::TYPE_STRING) && $rhs->is(ResolvedSymbol::TYPE_STRING):
                return new ResolvedSymbol(strpos($lhs->value(), $rhs->value()) === 0, false, ResolvedSymbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function endsWith(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(ResolvedSymbol::TYPE_ARRAY):
                $rhsArray = (array)$rhs->value();
                return new ResolvedSymbol($lhs->value() === $rhsArray[array_key_last($rhsArray)], false, ResolvedSymbol::TYPE_BOOL);

            case $lhs->is(ResolvedSymbol::TYPE_STRING) && $rhs->is(ResolvedSymbol::TYPE_STRING):
                return new ResolvedSymbol(strpos($lhs->value(), $rhs->value()) === (strlen($lhs->value()) - strlen($rhs->value())), false, ResolvedSymbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function pregMatch(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol(
            preg_match($rhs->value(), $lhs->value()) === 1,
            false,
            ResolvedSymbol::TYPE_BOOL
        );
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function is(Symbol $lhs, Symbol $rhs): Symbol
    {
        if ($rhs->is(Symbol::TYPE_BOOL)) {
            return $rhs;
        }

        return new ResolvedSymbol($lhs->value() === $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function isNot(Symbol $lhs, Symbol $rhs): Symbol
    {
        $is = $this->is($lhs, $rhs);
        return new ResolvedSymbol(!$is->value(), $is->interruptKeyPath(), $is->type());
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function and(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() && $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function or(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() || $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function xor(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() xor $rhs->value(), false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function range(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (
            (!$lhs->is(ResolvedSymbol::ANY_NUMERIC) && $lhs->is(ResolvedSymbol::TYPE_STRING))
            || (!$rhs->is(ResolvedSymbol::ANY_NUMERIC) && $rhs->is(ResolvedSymbol::TYPE_STRING))
        ) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
        return new ResolvedSymbol(range($lhs->value(), $rhs->value()), false, ResolvedSymbol::TYPE_ARRAY);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function concat(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() . $rhs->value(), $lhs->interruptKeyPath() || $rhs->interruptKeyPath(), ResolvedSymbol::TYPE_STRING);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function plus(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() + $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function minus(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() - $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function multiply(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() * $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function divide(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() / $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function mod(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() % $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function pow(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new ResolvedSymbol($lhs->value() ** $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function isInstanceof(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(ResolvedSymbol::TYPE_OBJECT) || !$rhs->is(ResolvedSymbol::TYPE_STRING)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        $object = $lhs->value();
        $className = $rhs->value(true);
        if (strpos($className, '\\\\') !== false) {
            $className = stripslashes($className);
        }

        return new ResolvedSymbol($object instanceof $className, false, ResolvedSymbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function isNotInstanceof(Symbol $lhs, Symbol $rhs): Symbol
    {
        $isInstance = $this->isInstanceof($lhs, $rhs);
        return new ResolvedSymbol(!$isInstance->value(), $isInstance->interruptKeyPath(), $isInstance->type());
    }
}
