<?php

namespace ricwein\Templater\Engine;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ricwein\Templater\Config;
use ricwein\Templater\Exceptions\UnexpectedValueException;

class CoreFunctions
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }


    /**
     * @return BaseFunction[]
     */
    public function get(): array
    {
        $exposeFunctions = [
            'abs' => 'abs',
            'join' => [$this, 'join'],
            'split' => [$this, 'split'],
            'range' => [$this, 'split'],
            'dump' => [$this, 'dump'],
            'lower' => 'strtolower',
            'upper' => 'strtoupper',
            'count' => 'count',
            'sum' => 'array_sum',
            'keys' => 'array_keys',
            'values' => 'array_values',
            'column' => 'array_column',
            'sorted' => [$this, 'sort'],
            'flip' => 'array_flip',
            'flat' => [$this, 'flat'],
            'first' => [$this, 'first'],
            'last' => [$this, 'last'],
            'empty' => [$this, 'empty'],
            'capitalize' => [$this, 'capitalize'],
            'format' => 'sprintf',
            'date' => [$this, 'date']
        ];

        $functions = [];
        $functions[] = new BaseFunction('escape', [$this, 'escape'], 'e');

        foreach ($exposeFunctions as $name => $callable) {
            $functions[] = new BaseFunction($name, $callable);
        }

        return $functions;
    }

    public function join(array $array, string $glue): string
    {
        return implode($glue, $array);
    }

    public function split(string $string, string $delimiter = '', ?int $limit = null): array
    {
        if (!empty($delimiter) && is_numeric($delimiter) && (strlen($delimiter) === strlen((string)(int)$delimiter))) {
            return str_split($string, (int)$delimiter);
        } else if (!empty($delimiter) && is_string($delimiter)) {
            return $limit !== null ? explode($delimiter, $string, $limit) : explode($delimiter, $string);
        }

        return str_split($string);
    }

    public function escape(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    public function range($start, $end, int $steps = 1)
    {
        return range($start, $end, $steps);
    }

    public function dump(...$variables): string
    {
        if (!$this->config->debug) {
            return '';
        }

        $result = [];
        foreach ($variables as $variable) {
            ob_start();
            var_dump($variable);
            $result[] = sprintf('<pre><code>%s</code></pre>', trim(ob_get_clean()));
        }

        return implode(PHP_EOL, $result);
    }

    public function flat(array $array): array
    {
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator((array)$array));
        $flattenedArray = [];
        foreach ($it as $innerValue) {
            $flattenedArray[] = $innerValue;
        }
        return $flattenedArray;
    }

    public function first(array $array)
    {
        return $array[array_key_first($array)];
    }

    public function last(array $array)
    {
        return $array[array_key_last($array)];
    }

    public function empty($var): bool
    {
        return empty($var);
    }

    public function capitalize(string $string): string
    {
        return ucfirst(strtolower($string));
    }

    /**
     * @inheritDoc
     * @throws UnexpectedValueException
     */
    public function date($time, string $format, ?string $modifier): string
    {
        switch (true) {

            case $time === null || strtolower(trim($time)) === 'now':
                if ($modifier !== null) {
                    return date($format, strtotime($modifier));
                }
                return date($format);

            case is_string($time):
            case is_int($time):
                return date($format, strtotime(trim("{$time} {$modifier}")));

        }

        throw new UnexpectedValueException(sprintf("Invalid Datatype for date() input: %s", gettype($time)), 500);
    }

    public function sort(array $array, ?string $compareFunction = null): array
    {
        if ($compareFunction === null) {
            asort($array);
        } else {
            uasort($array, $compareFunction);
        }

        return $array;
    }
}
