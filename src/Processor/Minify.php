<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Worker;

/**
 * trims html output
 */
class Minify extends Worker
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $content
     * @return string
     */
    public function replace(string $content): string
    {
        if ($this->config->debug) {
            return $content;
        }

        $regexReplaces = [
            '/\>[^\S ]+/s' => '>', // strip whitespaces after tags, except space
            '/[^\S ]+\</s' => '<', // strip whitespaces before tags, except space
            '/(\s)+/s' => '\\1', // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' => '', // Remove HTML comments
        ];

        return trim(preg_replace(array_keys($regexReplaces), array_values($regexReplaces), $content));
    }
}
