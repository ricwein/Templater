<?php

namespace ricwein\Templater\Engine;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\BaseFunction;
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

            new BaseFunction('capitalize', function (string $string): string {
                return ucfirst(strtolower($string));
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
