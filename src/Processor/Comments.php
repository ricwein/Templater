<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use ricwein\Templater\Config;
use ricwein\Templater\Engine\Worker;

/**
 * simple Template parser with Twig-like syntax
 */
class Comments extends Worker
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
        return preg_replace_callback('/{#\s*(.*)\s*#}/Us', function (array $match): string {

            if (!$this->config->stripComments && isset($match[1]) && !empty($match[1])) {
                return sprintf("<!-- %s -->", trim($match[1]));
            }

            return '';

        }, $content);
    }
}
