<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Config;
use ricwein\Templater\Processor;

/**
 * trims html output
 */
class Minify extends Processor
{
    private Config $config;

    public function __construct(string $content, Config $config)
    {
        parent::__construct($content);
        $this->config = $config;
    }

    public function process(): self
    {
        if ($this->config->debug) {
            return $this;
        }

        $regexReplaces = [
            '/\>[^\S ]+/s' => '>', // strip whitespaces after tags, except space
            '/[^\S ]+\</s' => '<', // strip whitespaces before tags, except space
            '/(\s)+/s' => '\\1', // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' => '', // Remove HTML comments
        ];

        $this->content = trim(preg_replace(
            array_keys($regexReplaces),
            array_values($regexReplaces),
            $this->content
        ));

        return $this;
    }
}
