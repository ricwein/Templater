<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Config;

/**
 * simple Template parser with Twig-like syntax
 */
class Comments extends Processor
{
    private Config $config;

    public function __construct(string $content, Config $config)
    {
        parent::__construct($content);
        $this->config = $config;
    }

    public function process(): self
    {
        $this->content = preg_replace_callback('/{#\s*(.*)\s*#}/Us', function (array $match): string {
            if (!$this->config->stripComments && isset($match[1]) && !empty($match[1])) {
                return sprintf("<!-- %s -->", trim($match[1]));
            }

            return '';
        }, $this->content);

        return $this;
    }
}
