<?php

namespace ricwein\Templater\Processors\Functions;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Processor;

class DebugDump extends Processor
{
    private Config $config;

    public function __construct(string $content, Config $config)
    {
        parent::__construct($content);
        $this->config = $config;
    }

    public function process(array $bindings = []): self
    {
        $this->content = preg_replace_callback('/{{\s*dump\(\s*(.+)\s*\)\s*}}/', function (array $match) use ($bindings): string {

            if (!$this->config->debug) {
                return '';
            }

            $var = (new Resolver($bindings))->resolve($match[1]);
            $result = static::dump($var);
            return sprintf('<pre><code>%s</code></pre>', $result);
        }, $this->content);

        return $this;
    }

    private static function dump($var): string
    {
        ob_start();
        var_dump($var);
        return trim(ob_get_clean());
    }
}
