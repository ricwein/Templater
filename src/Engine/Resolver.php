<?php

namespace ricwein\Templater\Engine;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ricwein\Templater\Exceptions\RuntimeException;

class Resolver
{
    private array $bindings;
    private static ?array $operators = null;

    public function __construct(array $bindings = [])
    {
        $this->bindings = $bindings;
    }

    /**
     * Casts input (string) $parameter to real data-type.
     * Resolves bindings if required.
     * @param string $parameter
     * @return array|bool|float|int|mixed|string
     * @throws RuntimeException
     */
    public function resolve(string $parameter)
    {
        // check if variable contains conditions (e.g. shorthand equations)
        $conditionBranch = null;
        $satisfiedBranch = null;
        $elseBranch = null;

        $openBlocks = [];

        foreach (str_split($parameter) as $offset => $char) {

            if ($conditionBranch === null && $char === '?' && empty($openBlocks)) {
                $conditionBranch = substr($parameter, 0, $offset);
                $satisfiedBranch = substr($parameter, $offset + 1);
                continue;
            }

            if ($elseBranch === null && $conditionBranch !== null && $char === ':' && empty($openBlocks)) {
                $elseBranch = substr($parameter, $offset + 1, strlen($parameter));
                $satisfiedBranch = substr($parameter, strlen($conditionBranch) + 1, strlen($parameter) - strlen($conditionBranch) - strlen($elseBranch) - 2);
                break;
            }

            $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
        }

        // no further conditions found
        if ($conditionBranch === null || $satisfiedBranch === null) {
            return $this->resolveVar($parameter);
        }

        // found shorthand condition, and it's satisfied
        if ($this->resolveCondition(trim($conditionBranch))) {
            return $this->resolve(trim($satisfiedBranch));
        }

        // found unsatisfied condition, but got an else branch
        if ($elseBranch !== null) {
            return $this->resolve(trim($elseBranch));
        }

        return '';
    }

    /**
     * Casts input (string) $parameter to real data-type.
     * Resolves bindings if required.
     * @param string $parameter
     * @return array|bool|float|int|mixed|string
     * @throws RuntimeException
     */
    private function resolveVar(string $parameter)
    {
        $parameter = trim($parameter);
        switch (true) {

            // handle explicit quoted strings
            case (strpos($parameter, '\'') === 0 && strrpos($parameter, '\'') === (strlen($parameter) - 1)):
                return trim($parameter, '\'');

            case (strpos($parameter, '"') === 0 && strrpos($parameter, '"') === (strlen($parameter) - 1)):
                return trim($parameter, '"');

            // handle inline arrays
            case (strpos($parameter, '[') === 0 && strrpos($parameter, ']') === (strlen($parameter) - 1)):
                return $this->convertStringToArray($parameter);

            // handle inline assocs
            case (strpos($parameter, '{') === 0 && strrpos($parameter, '}') === (strlen($parameter) - 1)):
                return $this->convertStringToAssoc($parameter);

            // handle real bool values
            case in_array($parameter, ['true', 'TRUE'], true):
                return true;

            case in_array($parameter, ['false', 'FALSE'], true):
                return false;

            // handle null values
            case in_array($parameter, ['null', 'NULL'], true):
                return null;

            // handle inline integers
            case strlen($parameter) === strlen((string)(int)$parameter):
                return (int)$parameter;

            // handle inline floats
            case strlen($parameter) === strlen((string)(float)$parameter):
                return (float)$parameter;

            default:
                return $this->resolveVarPathToValue($parameter);
        }
    }

    /**
     * Resolves variable path to final value.
     * @param string $variableName
     * @return array|mixed
     * @throws RuntimeException
     */
    private function resolveVarPathToValue(string $variableName)
    {
        $variablePath = static::splitContextStringBy('.', trim($variableName));

        // iterate vertically through bindings
        // where $current is always a subset of the previous iterations $current
        // if the first element is an inline array or object, use them instead of provided bindings
        $firstElement = reset($variablePath);
        if (isset($firstElement) && strpos($firstElement, '[') === 0 && strrpos($firstElement, ']') === (strlen($firstElement) - 1)) {
            $current = $this->convertStringToArray($firstElement);
            array_shift($variablePath);
        } elseif (isset($firstElement) && strpos($firstElement, '{') === 0 && strrpos($firstElement, '}') === (strlen($firstElement) - 1)) {
            $current = $this->convertStringToAssoc($firstElement);
            array_shift($variablePath);
        } else {
            $current = $this->bindings;
        }

        // keep track of the variable path which we walk down
        $previousValue = null;

        // traverse template variable
        /** @var string $value */
        foreach ($variablePath as $value) {

            // match against current bindings tree
            switch (true) {

                case is_array($current) && array_key_exists($value, $current):
                    $previousValue = $value;
                    $current = $current[$value];
                    break;

                case is_object($current) && (property_exists($current, $value) || isset($current->$value)):
                    $previousValue = $value;
                    $current = $current->$value;
                    break;

                case is_object($current) && (strrpos($value, ')') === (strlen($value) - 1) && strrpos($value, '(') === (strlen($value) - 2)) && method_exists($current, rtrim($value, '()')):
                    $previousValue = $value;
                    $current = $current->{rtrim($value, '()')}();
                    break;

                case is_array($current) && in_array(strtolower($value), ['first()', 'last()', 'count()', 'empty()', 'sum()', 'keys()', 'values()', 'flip()', 'flat()'], true):
                    switch (strtolower($value)) {

                        case 'first()':
                            $previousValue = array_key_first($current);
                            $current = $current[$previousValue];
                            break;

                        case 'last()':
                            $previousValue = array_key_last($current);
                            $current = $current[$previousValue];
                            break;

                        case 'count()':
                            $previousValue = null;
                            $current = count($current);
                            break;

                        case 'empty()':
                            $previousValue = null;
                            $current = empty($current);
                            break;

                        case 'sum()':
                            $previousValue = null;
                            $current = array_sum($current);
                            break;

                        case 'keys()':
                            $previousValue = null;
                            $current = array_keys($current);
                            break;

                        case 'values()':
                            $previousValue = null;
                            $current = array_values($current);
                            break;

                        case 'flip()':
                            $previousValue = null;
                            $current = array_flip($current);
                            break;

                        case 'flat()':
                            $previousValue = null;
                            $it = new RecursiveIteratorIterator(new RecursiveArrayIterator((array)$current));
                            $array = [];
                            foreach ($it as $innerValue) {
                                $array[] = $innerValue;
                            }
                            $current = $array;
                    }
                    break;

                case $previousValue !== null && is_scalar($previousValue) && in_array(strtolower($value), ['key()'], true):
                    $current = $previousValue;
                    $previousValue = $value;
                    break;

                default:
                    throw new RuntimeException("Unable to access element '{$value}' for variable: '{$variableName}'", 500);
            }
        }

        return $current;
    }

    /**
     * @param string $content
     * @return array
     * @throws RuntimeException
     */
    private function convertStringToAssoc(string $content): array
    {
        if (strpos($content, '{') === 0 && strrpos($content, '}') === (strlen($content) - 1)) {
            $content = trim(substr($content, 1, strlen($content) - 2));
        }

        $assoc = [];
        while (true) {
            $items = explode(':', $content, 2);

            if (count($items) !== 2) {
                throw new RuntimeException(sprintf("Invalid inline object declaration '%s'. Probably missing ':'-char.", $content), 500);
            }

            $key = $this->resolve(trim($items[0]));
            if (!is_scalar($key)) {
                throw new RuntimeException(sprintf("Invalid inline object declaration with key: %s. Type must be scalar but is: %s.", $items[0], is_object($key) ? get_class($key) : gettype($key)), 500);
            }

            // find object-value ending by tokenizing the body
            $openBlocks = [];
            $body = null;
            $input = trim($items[1]);
            foreach (str_split($input) as $offset => $char) {

                if ($char === ',' && empty($openBlocks)) {
                    $body = substr($input, 0, $offset);
                    $content = trim(substr($content, strpos($content, $body) + strlen($body) + 1));
                    break;
                }

                $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
            }

            if ($body !== null) {
                $value = $this->resolve(trim($body));
                $assoc[$key] = $value;
            } else {
                $value = $this->resolve($input);
                $assoc[$key] = $value;
                break;
            }
        }

        return $assoc;
    }

    private function convertStringToArray(string $content): array
    {
        if (strpos($content, '[') === 0 && strrpos($content, ']') === (strlen($content) - 1)) {
            $content = trim(substr($content, 1, strlen($content) - 2));
        }

        $bodies = static::splitContextStringBy(',', $content);
        $bodies = array_map(function (string $body) {
            return $this->resolve($body);
        }, $bodies);

        return $bodies;
    }

    private static function trackOpenBlocksFromToken(string $char, array $openBlocks): array
    {
        switch ($char) {
            case '{':
            case '[':
                $openBlocks[] = $char;
                return $openBlocks;

            case '}':
                $lastOpenBlock = end($openBlocks);
                if ($lastOpenBlock === '{') {
                    array_pop($openBlocks);
                }
                return $openBlocks;

            case ']':
                $lastOpenBlock = end($openBlocks);
                if ($lastOpenBlock === '[') {
                    array_pop($openBlocks);
                }
                return $openBlocks;

            case '\'':
                $lastOpenBlock = end($openBlocks);
                if ($lastOpenBlock === '\'') {
                    array_pop($openBlocks);
                } else {
                    $openBlocks[] = '\'';
                }
                return $openBlocks;

            case '"':
                $lastOpenBlock = end($openBlocks);
                if ($lastOpenBlock === '"') {
                    array_pop($openBlocks);
                } else {
                    $openBlocks[] = '"';
                }
                return $openBlocks;
        }

        return $openBlocks;
    }

    private static function splitContextStringBy(string $delimiter, string $string): array
    {
        $array = [];
        $delimiterLen = strlen($delimiter);

        while (true) {
            // find delimiter outside of blocks by tokenizing the body
            $openBlocks = [];
            $body = null;
            foreach (str_split($string) as $offset => $char) {

                if (empty($openBlocks) && $delimiter === substr($string, $offset, $delimiterLen)) {
                    $body = substr($string, 0, $offset);
                    $string = trim(substr($string, $offset + 1));
                    break;
                }

                $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
            }

            if ($body !== null) {
                $array[] = trim($body);
            } else {
                $array[] = trim($string);
                break;
            }
        }
        return $array;
    }

    /**
     * @param string $condition
     * @return bool
     * @throws RuntimeException
     */
    public function resolveCondition(string $condition): bool
    {
        $operators = [
            '>', '<', '==', '>=', '<=', '!=', 'in', 'not in', 'starts with', 'ends with'
        ];

        $lhs = null;
        $rhs = null;
        $operator = null;
        $openBlocks = [];

        foreach (str_split($condition) as $offset => $char) {
            foreach ($operators as $operatorChars) {
                if ($operatorChars === substr($condition, $offset, strlen($operatorChars))) {
                    $operator = $operatorChars;
                    $lhs = substr($condition, 0, $offset);
                    $rhs = substr($condition, $offset + strlen($operatorChars), strlen($condition) - ($offset + strlen($operatorChars)));
                    break 2;
                }
            }

            $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
        }

        if ($lhs === null || $rhs === null || $operator === null) {
            return $this->resolveSingleParameterCondition($condition);
        }

        return $this->resolveEquation(trim($lhs), trim($rhs), trim($operator));
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
            'in' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return in_array($lhs, $rhs, true);
                    case is_string($lhs) && is_string($rhs):
                        return strpos($rhs, $lhs) !== false;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            'not in' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return !in_array($lhs, $rhs, true);
                    case is_string($lhs) && is_string($rhs):
                        return strpos($rhs, $lhs) === false;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            'starts with' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return $lhs === $rhs[array_key_first($rhs)];
                    case is_string($lhs) && is_string($rhs):
                        return strpos($lhs, $rhs) === 0;
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
            'ends with' => function ($lhs, $rhs, string $operator): bool {
                switch (true) {
                    case is_array($rhs):
                        return $lhs === $rhs[array_key_last($rhs)];
                    case is_string($lhs) && is_string($rhs):
                        return strrpos($lhs, $rhs) === (strlen($lhs) - strlen($rhs));
                    default:
                        throw static::datatypeException($operator, $lhs, $rhs);
                }
            },
        ];

        return static::$operators;
    }


    /**
     * @param string $lVar
     * @param string $rVar
     * @param string $operator
     * @return bool
     * @throws RuntimeException
     */
    private function resolveEquation(string $lVar, string $rVar, string $operator): bool
    {
        $lhs = $this->resolve($lVar);
        $rhs = $this->resolve($rVar);

        $operators = static::getOperators();

        if (isset($operators[$operator])) {
            return $operators[$operator]($lhs, $rhs, $operator);
        }

        throw new RuntimeException("Invalid condition operator: {$operator}.", 500);
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
     * @param string $condition
     * @return bool
     * @throws RuntimeException
     */
    private function resolveSingleParameterCondition(string $condition): bool
    {
        $parameter = $this->resolve($condition);

        switch ($parameter) {
            case is_bool($parameter):
                return $parameter;

            case is_scalar($parameter):
            case is_array($parameter):
                return !empty($parameter);

            case is_object($parameter):
                return true;

            default:
                return false;
        }
    }
}
