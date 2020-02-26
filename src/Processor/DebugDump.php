<?php

namespace ricwein\Templater\Processor;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Engine\Worker;

class DebugDump extends Worker
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function replace(string $content, array $bindings = []): string
    {
        return preg_replace_callback('/{{\s*dump\(\s*(.+)\s*\)\s*}}/', function (array $match) use ($bindings): string {

            if (!$this->config->debug) {
                return '';
            }

            $var = (new Resolver($bindings))->resolve($match[1]);
            $result = static::dump($var);
            return sprintf('<pre><code>%s</code></pre>', $result);
        }, $content);
    }

    private static function dump($var): string
    {
        ob_start();
        var_dump($var);
        return trim(ob_get_clean());

    }
}
