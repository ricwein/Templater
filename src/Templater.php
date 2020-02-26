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
use ricwein\Templater\Exceptions\RuntimeException as TemplateRuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;

/**
 * simple Template parser with Twig-like syntax
 */
class Templater
{
    protected ?Directory $assetsDir;
    protected Directory $templateDir;

    protected Config $config;
    protected ?ExtendedCacheItemPoolInterface $cache = null;

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
            $templateFile = static::getTemplateFile($this->templateDir, $templateName, $this->config->fileExtension);
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

        // run parsers
        $content = (new Processor\BlockExtension($this->config, $this->templateDir))->replace($content, $bindings);
        $content = (new Processor\Includes($this->config, $this->templateDir))->replace($content, $bindings);
        $content = (new Processor\Comments($this->config))->replace($content);

        if ($this->assetsDir !== null) {
            $content = (new Processor\Assets($this->config, $this->assetsDir, $this->cache))->replace($content, $bindings);
        }

        // run user-defined filters above content
        if ($filter !== null) {
            $content = call_user_func_array($filter, [$content, $this]);
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

        $content = (new Processor\SetBindings())->replace($content, $bindings);
        $content = (new Processor\ForLoop())->replace($content, $bindings);
        $content = (new Processor\IfStatement($this->config))->replace($content, $bindings);

        $content = (new Processor\Implode($this->config))->replace($content, $bindings);
        $content = (new Processor\Date($this->config))->replace($content, $bindings);

        $content = (new Processor\DebugDump($this->config))->replace($content, $bindings);
        $content = (new Processor\Bindings($this->config))->replace($content, $bindings);
        $content = (new Processor\Minify($this->config))->replace($content);

        return $content;
    }


    /**
     * @param Directory $dir
     * @param string $filename
     * @param string $extension
     * @return File|null
     * @throws ConstraintsException
     * @throws AccessDeniedException
     * @throws Exception
     * @throws RuntimeException
     */
    public static function getTemplateFile(Directory $dir, string $filename, string $extension): ?File
    {
        $files = [
            $dir->file($filename),
            $dir->file("{$filename}{$extension}"),
        ];

        foreach ($files as $file) {
            if ($file->isFile()) {
                return $file;
            }
        }

        return null;
    }

}
