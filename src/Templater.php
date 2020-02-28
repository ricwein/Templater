<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater;

use Exception;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\DefaultFunctions;
use ricwein\Templater\Exceptions\RuntimeException as TemplateRuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Processors;

/**
 * simple Template parser with Twig-like syntax
 */
class Templater
{
    protected ?Directory $assetsDir;
    protected Directory $templateDir;

    protected Config $config;
    protected ?ExtendedCacheItemPoolInterface $cache = null;

    private array $functions = [];

    /**
     * @param Config $config
     * @param ExtendedCacheItemPoolInterface|null $cache
     * @throws AccessDeniedException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws TemplateRuntimeException
     * @throws UnexpectedValueException
     */
    public function __construct(Config $config, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        $this->config = $config;
        $this->cache = $cache;

        if (null === $templatePath = $config->templateDir) {
            throw new TemplateRuntimeException("Initialization of the Templater class requires Config::\$templateDir to be set, but is not.", 500);
        }
        $templateDir = new Directory(new Storage\Disk($templatePath), Constraint::IN_OPENBASEDIR);
        if (!$templateDir->isDir() && !$templateDir->isReadable()) {
            throw new FileNotFoundException("Unable to open the given template dir ({$templateDir->path()->raw}). Check if the directory exists and is readable.", 404);
        }
        $this->templateDir = $templateDir;


        if (null !== $assetPath = $config->assetDir) {
            $assetDir = new Directory(new Storage\Disk($assetPath), Constraint::IN_OPENBASEDIR);
            if (!$assetDir->isDir() && !$assetDir->isReadable()) {
                throw new FileNotFoundException("Unable to open the given asset dir ({$assetDir->path()->raw}). Check if the directory exists and is readable.", 404);
            }
            $this->assetsDir = $assetDir;
        }

        $this->loadDefaultFunctions();
    }

    private function loadDefaultFunctions()
    {
        foreach ((new DefaultFunctions($this->config))->get() as $function) {
            $this->addFunction($function);
        }
    }

    public function addFunction(BaseFunction $function): self
    {
        $this->functions[$function->getName()] = $function;
        return $this;
    }

    /**
     * @param File $templateFile
     * @param array $bindings
     * @param callable|null $filter
     * @return string
     * @throws RuntimeException
     * @throws TemplatingException
     */
    public function renderFile(File $templateFile, array $bindings = [], callable $filter = null): string
    {
        try {

            $bindings = array_replace_recursive($bindings, [
                'template' => ['file' => $templateFile]
            ]);

            if ($this->cache === null) {
                $content = $this->loadStaticTemplate($templateFile, $bindings, $filter);
                $content = $this->populateTemplate($content, $bindings);
                $content = trim($content);
                return $content;
            }

            $cacheKey = static::getCacheKeyFor($templateFile);
            $templateCache = $this->cache->getItem($cacheKey);

            if (null === $content = $templateCache->get()) {

                // load template from file
                $content = $this->loadStaticTemplate($templateFile, $bindings, $filter);

                $templateCache->set($content);
                $templateCache->expiresAfter($this->config->cacheDuration);
                $this->cache->save($templateCache);
            }

            $content = $this->populateTemplate($content, $bindings);
            $content = trim($content);
            return $content;
        } catch (Exception $exception) {
            throw new TemplatingException("Error rendering Template: {$templateFile->path()->filepath}", 500, $exception);
        }
    }

    /**
     * @param File $file
     * @return string
     * @throws RuntimeException
     */
    public static function getCacheKeyFor(File $file): string
    {
        return sprintf(
            "view.%s_%s",
            str_replace(
                ['{', '}', '(', ')', '/', '\\', '@', ':'],
                ['|', '|', '|', '|', '.', '.', '-', '_'],
                $file->path()->filepath
            ),
            hash('sha256', $file->getTime())
        );
    }

    /**
     * @param string $templateName
     * @param array|object $bindings
     * @param callable|null $filter
     * @return string
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws TemplatingException
     */
    public function render(string $templateName, array $bindings = [], callable $filter = null): string
    {
        try {
            $templateFile = static::getTemplateFile($this->templateDir, null, $templateName, $this->config->fileExtension);
        } catch (Exception $exception) {
            throw new FileNotFoundException("Error opening template: {$templateName}.", 404, $exception);
        }

        if ($templateFile === null) {
            throw new FileNotFoundException("No template file found for: {$templateName}.", 404);
        }

        return $this->renderFile($templateFile, $bindings, $filter);
    }

    /**
     * @param File $templateFile
     * @param array $bindings
     * @param callable|null $filter
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exceptions\UnexpectedValueException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws RuntimeException
     * @throws TemplateRuntimeException
     */
    protected function loadStaticTemplate(File $templateFile, array $bindings, callable $filter = null): string
    {
        // load template from file
        $content = $templateFile->read();

        $content = $this->buildBaseTemplate($content, $bindings);


        // run user-defined filters above content
        if ($filter !== null) {
            $content = call_user_func_array($filter, [$content]);
        }

        return $content;
    }

    /**
     * @param string $content
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exceptions\UnexpectedValueException
     * @throws FileNotFoundException
     * @throws FileSystemException
     * @throws RuntimeException
     * @throws TemplateRuntimeException
     * @throws UnexpectedValueException
     */
    protected function buildBaseTemplate(string $content, array $bindings): string
    {
        // repeat until all blocks and includes are resolved
        do {

            $blockProcessor = (new Processors\Recursive\BlockExtensions($content, $this->config, $this->templateDir))->process($bindings, $this->functions);
            $hasReplacedBlocks = $blockProcessor->hasMatched();
            $content = $blockProcessor->getResult();

            $includeProcessor = (new Processors\Recursive\Includes($content, $this->config, $this->templateDir))->process($bindings, $this->functions);
            $hasReplacedIncludes = $includeProcessor->hasMatched();
            $content = $includeProcessor->getResult();

        } while ($hasReplacedBlocks || $hasReplacedIncludes);

        // cleanup remaining un-extended blocks
        $content = (new Processors\Recursive\BlockExtensions($content, $this->config, $this->templateDir))->cleanup()->getResult();
        $content = (new Processors\Comments($content, $this->config))->process()->getResult();

        if ($this->assetsDir !== null) {
            $content = (new Processors\Assets($content, $this->config, $this->assetsDir, $this->cache))->process($bindings, $this->functions)->getResult();
        }

        return $content;
    }

    /**
     * @param string $content
     * @param array $bindings
     * @return string
     * @throws Exception
     */
    protected function populateTemplate(string $content, array $bindings): string
    {
        $bindings = array_replace_recursive($bindings, (array)$this->config->variables);

        // preprocessing
        $content = (new Processors\SetBindings($content))->process($bindings, $this->functions)->getResult();
        $content = (new Processors\ForLoop($content))->process($bindings, $this->functions)->getResult();
        $content = (new Processors\IfStatement($content, $this->config))->process($bindings, $this->functions)->getResult();

        // fill remaining variables
        $content = (new Processors\Bindings($content, $this->config))->process($bindings, $this->functions)->getResult();

        // postprocessing
        $content = (new Processors\Minify($content, $this->config))->process()->getResult();

        return $content;
    }

    /**
     * @param Directory $baseDir
     * @param Directory $relativeDir
     * @param string $filename
     * @param string $extension
     * @return File|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public static function getTemplateFile(Directory $baseDir, ?Directory $relativeDir, string $filename, string $extension): ?File
    {
        /** @var Directory[] $dirs */
        $dirs = array_filter([$baseDir, $relativeDir], function (?Directory $dir): bool {
            return $dir !== null;
        });

        foreach ($dirs as $dir) {
            foreach ([$filename, "{$filename}{$extension}"] as $filenameVariation) {
                $file = $dir->file($filenameVariation);
                if ($file->isFile()) {
                    return $file;
                }
            }
        }

        return null;
    }
}
