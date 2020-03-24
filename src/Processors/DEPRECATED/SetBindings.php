<?php


namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Resolver\Resolver;
use ricwein\Templater\Exceptions\RuntimeException;

class SetBindings extends Processor
{
    /**
     * @param array|null &$bindings
     * @param BaseFunction[] $functions
     * @return $this
     */
    public function process(?array &$bindings = null, array $functions = []): self
    {
        $this->content = preg_replace_callback('/{%\s*set\s+(.+)\s*=\s*(.+)\s*%}/', function (array $match) use (&$bindings, $functions): string {

            if (count($match) !== 3) {
                throw new RuntimeException('Invalid match-count for {% set %} processing.', 500);
            } elseif ($bindings === null) {
                return '';
            }

            $value = (new Resolver($bindings, $functions))->resolve($match[2]);
            $key = trim($match[1], '\'" ');
            $keyPath = explode('.', $key);

            $variable = static::chainArray($keyPath, $value);
            $bindings = array_replace_recursive($bindings, $variable);

            return '';
        }, $this->content);

        return $this;
    }


    /**
     * @param array $keys
     * @param mixed $value
     * @return array
     */
    private static function chainArray(array $keys, $value): array
    {
        $insert = [];
        $key = array_shift($keys);

        // abort condition
        if (count($keys) <= 0) {
            $insert[$key] = $value;
            return $insert;
        }

        // recursive call for infinite deep hierarchic arrays
        $insert[$key] = static::chainArray($keys, $value);
        return $insert;
    }
}
