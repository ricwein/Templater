<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors\Recursive;

use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Exceptions\Exception;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\RecursiveProcessor;
use ricwein\Templater\Templater;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Helper\Constraint;

/**
 * simple Template parser with Twig-like syntax
 */
class Includes extends RecursiveProcessor
{
    private Config $config;
    private Directory $templateBaseDir;

    public function __construct(string $content, Config $config, Directory $templateBaseDir)
    {
        parent::__construct($content);
        $this->config = $config;
        $this->templateBaseDir = $templateBaseDir;
    }

    public function process(array $bindings = []): self
    {
        if (preg_match('/{%\s*include\s*(.+)\s*%}/', $this->content) === 1) {
            $this->content = $this->includeTemplate($this->templateBaseDir, null, $this->content, $bindings, 0);
            $this->matchedAction = true;
        }

        return $this;
    }

    protected function includeTemplate(Directory $baseDir, ?Directory $relativeDir, string $content, array $bindings, int $currentDepth): string
    {
        // include other template files
        $content = preg_replace_callback('/{%\s*include\s*(.+)\s*%}/U', function ($match) use ($baseDir, $relativeDir, $currentDepth, $bindings) {

            $filename = (new Resolver($bindings))->resolve(trim($match[1]));
            $includeFile = Templater::getTemplateFile($baseDir, $relativeDir, $filename, $this->config->fileExtension);
            if ($includeFile === null) {
                throw new FileNotFoundException("template '{$filename}' not found", 404);
            }

            $fileContent = $includeFile->read();
            $fileDir = $includeFile->directory(Constraint::IN_OPENBASEDIR);

            try {
                $blockProcessor = (new BlockExtensions($fileContent, $this->config, $baseDir))->process($bindings, $fileDir);
                $fileContent = $blockProcessor->getResult();
            } catch (Exception $exception) {
                throw new TemplatingException("Error rendering Template: {$includeFile->path()->filepath}", 500, $exception);
            }

            // depth - 2 since we already have the original + current depth
            if ($currentDepth > static::MAX_DEPTH) {
                throw new RuntimeException(sprintf("Exceeded template extension maximum depth of %d iterations.", static::MAX_DEPTH), 400);
            }

            return $this->includeTemplate(
                $baseDir,
                $fileDir,
                trim($fileContent, PHP_EOL),
                $bindings,
                $currentDepth + 1
            );
        }, $content);

        return $content;
    }
}
