<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use Exception;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Engine\Worker;

/**
 * implode method, allowing array joining with given glue
 */
class Implode extends Worker
{

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function replace(string $content, array $bindings = []): string
    {
        // replace all variables
        $content = preg_replace_callback('/{{\s*([^}]+)\|\s*implode\([\"|\']([^}]+)[\"|\']\)\s*}}/', function ($match) use ($bindings): string {

            try {
                $current = (new Resolver($bindings))->resolve($match[1]);
                $glue = stripslashes($match[2]);
            } catch (Exception $exception) {
                if ($this->config->debug) {
                    throw $exception;
                }
                return '';
            }

            // check for return type
            if ($current === $bindings) {
                return '';
            } elseif (is_array($current) || is_object($current)) {
                $array = (array)$current;

                if (count(array_filter($array, 'is_array')) > 0) {
                    throw new RuntimeException("Array to string conversion for: {$match[1]}", 500);
                }
                return implode($glue, $array);
            } elseif (is_iterable($current)) {
                $values = iterator_to_array($current);
                return implode($glue, (array)$values);
            }

            return '';
        }, $content);

        return $content;
    }
}
