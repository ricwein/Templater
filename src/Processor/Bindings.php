<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Engine\Worker;
use ricwein\Templater\Exceptions\RuntimeException;

/**
 * replaces twig variables with values from arrays or objects
 */
class Bindings extends Worker
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $content
     * @param array|object|null $bindings variables to be replaced
     * @return string
     */
    public function replace(string $content, $bindings = null): string
    {
        if ($bindings === null) {
            return $content;
        }

        // replace all variables
        $content = preg_replace_callback('/{{\s*(.+)\s*}}/U', function (array $match) use ($bindings): string {

            try {
                $current = (new Resolver($bindings))->resolve($match[1]);
            } catch (RuntimeException $exception) {
                if ($this->config->debug) {
                    throw $exception;
                }
                return '';
            }

            // check for return type
            if (is_scalar($current)) {
                return $current;
            } elseif (is_object($current) && method_exists($current, '__toString')) {
                return (string)$current;
            }

            if ($this->config->debug) {
                throw new RuntimeException(sprintf(
                    "Unable to print non-scalar value for '%s' (type: %s)",
                    trim($match[1]),
                    is_object($current) ? sprintf('class: %s', get_class($current)) : gettype($current)
                ));
            }

            return '';
        }, $content);

        return $content;
    }
}
