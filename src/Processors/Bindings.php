<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Resolver\Resolver;
use ricwein\Templater\Exceptions\RuntimeException;

/**
 * replaces twig variables with values from arrays or objects
 */
class Bindings extends Processor
{
    private Config $config;

    public function __construct(string $content, Config $config)
    {
        parent::__construct($content);
        $this->config = $config;
    }

    /**
     * @param array $bindings
     * @param BaseFunction[] $functions
     * @return $this
     */
    public function process($bindings = [], array $functions = []): self
    {
        // replace all variables
        $this->content = preg_replace_callback('/{{\s*(.+)\s*}}/U', function (array $match) use ($bindings, $functions): string {

            try {
                $current = (new Resolver($bindings, $functions))->resolve($match[1]);
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
        }, $this->content);

        return $this;
    }
}
