<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use Exception;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Engine\Worker;
use ricwein\Templater\Exceptions\UnexpectedValueException;

/**
 * implode method, allowing array joining with given glue
 */
class Date extends Worker
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function replace(string $content, array $bindings = []): string
    {
        // replace all variables
        $content = preg_replace_callback('/{{\s*([^}]+)\|\s*date\((.+)\)\s*}}/Us', function ($match) use ($bindings): string {

            try {
                $resolver = new Resolver($bindings);
                $variable = $resolver->resolve($match[1]);
                $format = $resolver->resolve($match[2]);

                switch (true) {
                    case $variable === null:
                        return date($format);
                    case is_int($variable):
                        return date($format, $variable);
                    case is_string($variable):
                        return date($format, strtotime($variable));
                    default:
                        throw new UnexpectedValueException('Invalid Datatype for date() input: ' . gettype($variable), 500);
                }

            } catch (Exception $exception) {
                if ($this->config->debug) {
                    throw $exception;
                }
                return '';
            }

        }, $content);

        return $content;
    }
}
