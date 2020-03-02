<?php

namespace ricwein\Templater\Engine;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ReflectionException;
use ricwein\Templater\Exceptions\RuntimeException;

class Resolver
{
    private array $bindings;

    /**
     * @var BaseFunction[]
     */
    private array $functions;

    private static ?array $operators = null;

    public function __construct(array $bindings = [], array $functions = [])
    {
        $this->bindings = $bindings;
        $this->functions = $functions;
    }

    /**
     * Casts input (string) $parameter to real data-type.
     * Resolves bindings if required.
     * @param string $parameter
     * @return array|bool|float|int|mixed|string
     * @throws ReflectionException
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
            return $this->resolveVarPathToValue($parameter);
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
     * @param string $functionString
     * @return array [BaseFunction, array]
     * @throws RuntimeException
     * @throws ReflectionException
     */
    private function splitFunctionString(string $functionString): ?array
    {
        $splitted = static::splitContextStringBy('(', $functionString, true);
        $name = trim($splitted[0]);
        $function = $this->getFunction($name);

        if ($function === null) {
            return null;
        }

        $parameters = [];
        if (count($splitted) > 1) {
            $parameterString = $splitted[1];
            if (strrpos($parameterString, ')') === (strlen($parameterString) - 1)) {
                $parameterString = substr($parameterString, 0, strlen($parameterString) - 1);
            }
            $parameters = static::splitContextStringBy(',', $parameterString);
            $parameters = array_map('trim', $parameters);
            $parameters = array_filter($parameters, function (string $var): bool {
                return !empty($var);
            });
        }

        $requiredParameters = $function->getNumberOfRequiredParameters();

        if (count($parameters) < ($requiredParameters - 1)) {
            throw new RuntimeException(sprintf("Too few arguments to function %s(), %d passed and exactly %d expected.", $function->getName(), count($parameters), $requiredParameters), 500);
        }

        $parameters = array_map(function (string $var) {
            return $this->resolve($var);
        }, $parameters);

        return [
            $function, $parameters
        ];
    }

    /**
     * Resolves variable path to final value.
     * @param string $variableName
     * @return array|mixed
     * @throws ReflectionException
     * @throws RuntimeException
     */
    private function resolveVarPathToValue(string $variableName)
    {
        if (is_numeric($variableName)) {
            if (strlen($variableName) === strlen((string)(int)$variableName)) {
                return (int)$variableName;
            } else if (strlen($variableName) === strlen((string)(float)$variableName)) {
                return (float)$variableName;
            }
            return (float)$variableName;
        }

        $variableName = trim($variableName);
        $keyPath = static::splitContextStringOnDelimiters(['.', '|', ']'], $variableName);

        // iterate vertically through bindings
        // where $current is always a subset of the previous iterations $current
        // if the first element is an inline array or object, use them instead of provided bindings
        $firstElement = reset($keyPath)['content'];
        if (isset($firstElement) && strpos($firstElement, '[') === 0 && strrpos($firstElement, ']') === (strlen($firstElement) - 1)) {
            $value = $this->convertStringToArray($firstElement);
            array_shift($keyPath);
        } elseif (isset($firstElement) && strpos($firstElement, '{') === 0 && strrpos($firstElement, '}') === (strlen($firstElement) - 1)) {
            $value = $this->convertStringToAssoc($firstElement);
            array_shift($keyPath);
        } else {
            $value = $this->bindings;
        }

        // keep track of the variable path which we walk down
        $previousKey = null;

        // traverse template variable
        /** @var string $key */
        foreach ($keyPath as $keyPathPart) {
            $key = $keyPathPart['content'];
            $delimiter = $keyPathPart['delimiter'];

            // handle inline declarations
            if ($delimiter === null) {
                switch (true) {

                    // handle explicit quoted strings
                    case (strpos($key, '\'') === 0 && strrpos($key, '\'') === (strlen($key) - 1)):
                        $value = trim($key, '\'');
                        $previousKey = null;
                        continue 2;
                    case (strpos($key, '"') === 0 && strrpos($key, '"') === (strlen($key) - 1)):
                        $value = trim($key, '"');
                        $previousKey = null;
                        continue 2;

                    // handle inline arrays
                    case (strpos($key, '[') === 0 && strrpos($key, ']') === (strlen($key) - 1)):
                        $value = $this->convertStringToArray($key);
                        $previousKey = null;
                        continue 2;

                    // handle inline assocs
                    case (strpos($key, '{') === 0 && strrpos($key, '}') === (strlen($key) - 1)):
                        $value = $this->convertStringToAssoc($key);
                        $previousKey = null;
                        continue 2;

                    // handle real bool values
                    case in_array($key, ['true', 'TRUE'], true):
                        $value = true;
                        $previousKey = null;
                        continue 2;
                    case in_array($key, ['false', 'FALSE'], true):
                        $value = false;
                        $previousKey = null;
                        continue 2;

                    // handle null values
                    case in_array($key, ['null', 'NULL'], true):
                        $value = null;
                        $previousKey = null;
                        continue 2;

                    // handle inline integers
                    case strlen($key) === strlen((string)(int)$key):
                        $value = (int)$key;
                        $previousKey = null;
                        continue 2;

                    // handle inline floats
                    case strlen($key) === strlen((string)(float)$key):
                        $value = (float)$key;
                        $previousKey = null;
                        continue 2;
                }
            }

            // check for array-like access notation
            if (strrpos($key, ']') === (strlen($key) - 1) && false !== $startPos = strpos($key, '[')) {

                $arrayKey = substr($key, $startPos + 1, -1);
                $key = trim(substr($key, 0, $startPos));

                $value = $this->getValueForKeyInObject($key, $value, $previousKey, $delimiter, $variableName);
                $key = $this->resolve($arrayKey);

                $value = $this->getValueForKeyInObject($key, $value, $previousKey, null, $variableName);
                continue;
            }

            // the last delimiter was an pipe | which requires an function-call next:
            if ($delimiter === '|') {
                $resolvedFunction = $this->splitFunctionString($key);
                if ($resolvedFunction === null) {
                    throw new RuntimeException("Call to unknown function '{$key}'", 500);
                }

                [$function, $parameters] = $resolvedFunction;
                array_unshift($parameters, $value);
                $previousKey = null;
                $value = $function->call($parameters);

                continue;
            }

            $value = $this->getValueForKeyInObject($key, $value, $previousKey, $delimiter, $variableName);
        }

        return $value;
    }

    /**
     * Fetch sub-entry from bindings/functions for single key
     * @param string $key
     * @param $source
     * @param string &$previousKey
     * @param null|string $delimiter
     * @param string $variableName
     * @return array|bool|float|int|mixed|string
     * @throws ReflectionException
     * @throws RuntimeException
     */
    private function getValueForKeyInObject(string $key, $source, ?string &$previousKey, ?string $delimiter, string $variableName)
    {

        // match against current bindings tree
        switch (true) {

            case is_array($source) && array_key_exists($key, $source):
                $previousKey = $key;
                return $source[$key];

            case is_object($source) && (property_exists($source, $key) || isset($source->$key)):
                $previousKey = $key;
                return $source->$key;

            case is_object($source) && (strrpos($key, ')') === (strlen($key) - 1) && strrpos($key, '(') === (strlen($key) - 2)) && method_exists($source, rtrim($key, '()')):
                $previousKey = $key;
                return $source->{rtrim($key, '()')}();

            case $previousKey !== null && is_scalar($previousKey) && in_array(strtolower($key), ['key()'], true):
                $source = $previousKey;
                $previousKey = $key;
                return $source;

            // handle leading function calls
            case null !== $resolvedFunction = $this->splitFunctionString($key):
                if ($delimiter !== null) {
                    throw new RuntimeException("Unexpected function-call to: {$key}()", 500);
                }
                [$function, $parameters] = $resolvedFunction;
                $previousKey = null;
                return $function->call($parameters);
                break;

            default:
                throw new RuntimeException("Unable to resolve variable path '{$variableName}'. Unknown key: '{$key}'", 500);
        }
    }

    /**
     * @param string $content
     * @return array
     * @throws ReflectionException
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
            case '(':
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

            case ')':
                $lastOpenBlock = end($openBlocks);
                if ($lastOpenBlock === '(') {
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

    public static function splitContextStringOnDelimiters(array $delimiters, string $string, bool $abortAfterFirstMatch = false): array
    {
        $parts = [];

        $delimiterList = [];
        foreach ($delimiters as $del) {
            $delimiterList[] = ['len' => strlen($del), 'del' => $del];
        }

        $lastDelimiter = null;
        $currentDelimiter = null;
        $lastOffset = 0;
        $remaining = $string;
        $openBlocks = [];

        foreach (str_split($string) as $offset => $char) {

            if (!empty($openBlocks)) {
                $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
                continue;
            }

            foreach ($delimiterList as $del) {
                if ($del['del'] === substr($string, $offset, $del['len'])) {

                    $content = substr($string, $lastOffset, $offset - $lastOffset);
                    $remaining = substr($string, $offset + $del['len']);

                    $currentDelimiter = $del['del'];
                    $lastOffset = $offset + $del['len'];

                    $parts[] = ['content' => trim($content), 'delimiter' => $lastDelimiter];

                    if ($abortAfterFirstMatch) {
                        $parts[] = ['content' => trim($remaining), 'delimiter' => $currentDelimiter];
                        return $parts;
                    }

                    $lastDelimiter = $currentDelimiter;

                    $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
                    continue 2;
                }
            }

            $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
        }

        $parts[] = ['content' => trim($remaining), 'delimiter' => $currentDelimiter];

        return $parts;
    }

    private static function splitContextStringBy(string $delimiter, string $string, bool $abortAfterFirstMatch = false): array
    {
        $parts = [];
        $delimiterLen = strlen($delimiter);

        while (true) {
            // find delimiter outside of blocks by tokenizing the body
            $openBlocks = [];
            $body = null;
            foreach (str_split($string) as $offset => $char) {

                if (empty($openBlocks) && $delimiter === substr($string, $offset, $delimiterLen)) {
                    $body = substr($string, 0, $offset);
                    $string = trim(substr($string, $offset + 1));

                    if ($abortAfterFirstMatch) {
                        return [trim($body), trim($string)];
                    }
                    break;
                }

                $openBlocks = static::trackOpenBlocksFromToken($char, $openBlocks);
            }

            if ($body !== null) {
                $parts[] = trim($body);
            } else {
                $parts[] = trim($string);
                break;
            }
        }
        return $parts;
    }

    /**
     * @param string $condition
     * @return bool
     * @throws ReflectionException
     * @throws RuntimeException
     */
    public function resolveCondition(string $condition): bool
    {
        $operators = array_keys(static::getOperators());

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

        return $this->resolveEquation(trim($lhs), trim($rhs), $operator);
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
        ];

        return static::$operators;
    }


    /**
     * @param string $lVar
     * @param string $rVar
     * @param string $operator
     * @return bool
     * @throws ReflectionException
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
     * @throws ReflectionException
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
