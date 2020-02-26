<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Engine;

use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\Templater\Config;
use ricwein\Templater\Templater;
use ScssPhp\ScssPhp\Compiler;
use ScssPhp\ScssPhp\Formatter\Crunched as FormatterCrunched;
use ScssPhp\ScssPhp\Formatter\Expanded as FormatterExpanded;
use MatthiasMullie\Minify;

class AssetParser
{
    private Directory $baseDir;
    private ?ExtendedCacheItemPoolInterface $cache = null;
    private Config $config;

    public function __construct(Directory $baseDir, Config $config, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        $this->baseDir = $baseDir;
        $this->config = $config;
        $this->cache = $cache;
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function parse(File $assetFile, array $bindings = []): string
    {
        if ($this->cache === null) {
            return $this->parseNew($assetFile, $bindings);
        }

        if (!$assetFile->isFile() || !$assetFile->isReadable()) {
            throw new FileNotFoundException("File {$assetFile->path()->filepath} not found!", 404);
        }

        $cacheKey = Templater::getCacheKeyFor($assetFile);
        $cacheItem = $this->cache->getItem($cacheKey);

        if (null === $asset = $cacheItem->get()) {

            $asset = $this->parseNew($assetFile);

            $cacheItem->set($asset);
            $cacheItem->expiresAfter($this->config->cacheDuration);
            $this->cache->save($cacheItem);
        }

        return $asset;
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function parseNew(File $assetFile, array $bindings = []): string
    {
        // load template from file
        /** @var string|null $asset */
        $asset = null;
        switch ($assetFile->path()->extension) {

            case 'css':
                return $this->parseNewCss($assetFile);

            case 'scss':
            case 'sass':
                return $this->parseNewScss($assetFile, $bindings);

            case 'js':
                return $this->parseNewScript($assetFile);
        }

        throw new \RuntimeException("error while processing assetfile '{$assetFile->path()->filename}': invalid extension '{$assetFile->path()->extension}", 500);
    }

    /**
     * @param File $assetFile
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     */
    private function parseNewCss(File $assetFile): string
    {
        if ($this->config->debug) {
            return $assetFile->read();
        }

        $content = $assetFile->read();
        $minifier = new Minify\CSS($content);
        return $minifier->minify();
    }

    /**
     * @param File $assetFile
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function parseNewScss(File $assetFile, array $bindings = []): string
    {
        /**
         * @var Compiler
         */
        static $compiler;

        $bindings = array_replace_recursive($this->config->variables, (array)array_filter($bindings, function ($entry): bool {
            return is_scalar($entry) || (is_object($entry) && method_exists($entry, '__toString'));
        }));

        if ($compiler === null) {
            $compiler = new Compiler();
            $compiler->setImportPaths([$this->baseDir->path()->real]);

            if ($this->config->debug) {
                $compiler->setFormatter(new FormatterExpanded());
            } else {
                $compiler->setFormatter(new FormatterCrunched());
            }
        }

        $compiler->setVariables($bindings);
        $compiler->addImportPath($assetFile->directory()->path()->real);
        $compiler->addImportPath($assetFile->path()->real);

        $filecontent = $assetFile->read();
        return $compiler->compile($filecontent);
    }

    /**
     * @param File $assetFile
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     */
    private function parseNewScript(File $assetFile): string
    {
        if ($this->config->debug) {
            return $assetFile->read();
        }

        $content = $assetFile->read();
        $minifier = new Minify\JS($content);
        return $minifier->minify();
    }
}
