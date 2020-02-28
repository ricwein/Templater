<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processor;

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

    public function process($bindings = []): self
    {
        // replace all variables
        $this->content = preg_replace_callback('/{{\s*(.+)\s*}}/U', function (array $match) use ($bindings): string {

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
        }, $this->content);

        return $this;
    }
}
