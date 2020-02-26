<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Engine\Worker;
use ricwein\Templater\Templater;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Helper\Constraint;

/**
 * simple Template parser with Twig-like syntax
 */
class Includes extends Worker
{
    private Config $config;
    private Directory $basedir;

    public function __construct(Config $config, Directory $templateDir)
    {
        $this->config = $config;
        $this->basedir = $templateDir;
    }

    /**
     * @param string $content
     * @param array $bindings
     * @return string
     */
    public function replace(string $content, array $bindings = []): string
    {
        return $this->includeTemplate($this->basedir, $content, $bindings, 0);
    }

    protected function includeTemplate(Directory $basedir, string $content, array $bindings, int $currentDepth): string
    {
        // include other template files
        $content = preg_replace_callback('/{%\s*include(.*)\s*%}/U', function ($match) use ($basedir, $currentDepth, $bindings) {

            $filename = (new Resolver($bindings))->resolve(trim($match[1]));
            $includeFile = Templater::getTemplateFile($basedir, $filename, $this->config->fileExtension);

            if ($includeFile === null) {
                throw new FileNotFoundException("template '{$filename}' not found", 404);
            }

            $fileContent = $includeFile->read();

            // depth - 2 since we already have the original + current depth
            if ($currentDepth <= (self::MAX_DEPTH - 2)) {
                return $this->includeTemplate($includeFile->directory(Constraint::IN_OPENBASEDIR), $fileContent, $bindings, $currentDepth + 1);
            }

            return $fileContent;
        }, $content);

        return $content;

    }
}
