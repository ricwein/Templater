<?php

namespace ricwein\Templater\Engine;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ricwein\Templater\Config;
use ricwein\Templater\Exceptions\UnexpectedValueException;

class DefaultFunctions
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
        return [
            new BaseFunction('join', function (array $array, string $glue): string {
                return implode($glue, $array);
            }),

            new BaseFunction('escape', function (string $string): string {
                return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE);
            }, 'e'),

            new BaseFunction('range', function ($start, $end, int $steps = 1) {
                return range($start, $end, $steps);
            }),

            new BaseFunction('dump', function (...$variables): string {
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
            }),

            new BaseFunction('lower', function (string $string): string {
                return strtolower($string);
            }),

            new BaseFunction('upper', function (string $string): string {
                return strtoupper($string);
            }),

            new BaseFunction('count', function ($input): int {
                return count($input);
            }),

            new BaseFunction('sum', function (array $array) {
                return array_sum($array);
            }),

            new BaseFunction('keys', function (array $array): array {
                return array_keys($array);
            }),

            new BaseFunction('values', function (array $array): array {
                return array_values($array);
            }),

            new BaseFunction('flip', function (array $array): array {
                return array_flip($array);
            }),

            new BaseFunction('flat', function (array $array): array {
                $it = new RecursiveIteratorIterator(new RecursiveArrayIterator((array)$array));
                $flattenedArray = [];
                foreach ($it as $innerValue) {
                    $flattenedArray[] = $innerValue;
                }
                return $flattenedArray;
            }),

            new BaseFunction('first', function (array $array) {
                return $array[array_key_first($array)];
            }),

            new BaseFunction('last', function (array $array) {
                return $array[array_key_last($array)];
            }),

            new BaseFunction('empty', function ($var): bool {
                return empty($var);
            }),

            new BaseFunction('capitalize', function (string $string): string {
                return ucfirst(strtolower($string));
            }),

            new BaseFunction('format', function (string $format, ...$parameters): string {
                return sprintf($format, ...$parameters);
            }),

            new BaseFunction('date', function ($time, string $format): string {
                switch (true) {
                    case $time === null:
                        return date($format);
                    case is_int($time):
                        return date($format, $time);
                    case is_string($time):
                        return date($format, strtotime($time));
                    default:
                        throw new UnexpectedValueException(sprintf("Invalid Datatype for date() input: %s", gettype($time)), 500);
                }
            }),
        ];
    }
}
