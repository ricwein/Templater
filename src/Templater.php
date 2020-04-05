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
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException as FileSystemUnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Processors;
use ricwein\Templater\Resolver\TemplateResolver;

/**
 * simple Template parser with Twig-like syntax
 */
class Templater
{
    protected Directory $templateDir;

    protected Config $config;
    protected ?ExtendedCacheItemPoolInterface $cache = null;

    /**
     * @var BaseFunction[]
     */
    private array $functions = [];

    /**
     * @var string[]
     */
    private array $processors;

    /**
     * @param Config $config
     * @param ExtendedCacheItemPoolInterface|null $cache
     * @throws AccessDeniedException
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws FileSystemUnexpectedValueException
     */
    public function __construct(Config $config, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        $this->config = $config;
        $this->cache = $cache;

        if (null === $templatePath = $config->templateDir) {
            throw new RuntimeException('Initialization of the Templater class requires Config::$templateDir to be set, but is not.', 500);
        }

        $templateDir = new Directory(new Storage\Disk($templatePath), Constraint::IN_OPENBASEDIR);
        if (!$templateDir->isDir() && !$templateDir->isReadable()) {
            throw new FileNotFoundException("Unable to open the given template dir ({$templateDir->path()->raw}). Check if the directory exists and is readable.", 404);
        }

        $this->templateDir = $templateDir;

        // setup core processors
        $this->processors = [
            Processors\ExtendsProcessor::class,
            Processors\BlockProcessor::class,
//            Processors\UseProcessor::class,
            Processors\IncludeProcessor::class,
            Processors\IfProcessor::class,
            Processors\ForLoopProcessor::class,
            Processors\SetProcessor::class,
            Processors\CacheProcessor::class,
            Processors\ApplyProcessor::class,
        ];
    }

    public function addFunction(BaseFunction $function): self
    {
        $this->functions[$function->getName()] = $function;
        return $this;
    }

    /**
     * @param array $processors
     * @return $this
     * @throws RuntimeException
     */
    public function addProcessors(array $processors): self
    {
        foreach ($processors as $processor) {
            $this->addProcessor($processor);
        }
        return $this;
    }

    /**
     * @param string $processor
     * @return $this
     * @throws RuntimeException
     */
    public function addProcessor(string $processor): self
    {
        if (!is_subclass_of($processor, Processors\Processor::class, true)) {
            throw new RuntimeException(sprintf(
                "Processors must extend the class '%s', but %s doesn't",
                Processors\Processor::class, $processor
            ), 500);
        }
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * @param string $templateName
     * @param array|object $bindings
     * @param callable|null $filter
     * @return string
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
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

        try {

            return $this->renderFile($templateFile, $bindings, $filter);

        } catch (RenderingException $exception) {

            if ($exception->getTemplateFile() === null) {
                $exception->setTemplateFile($templateFile);
            }
            throw $exception;

        } catch (Exception $exception) {

            throw new TemplatingException(
                "Error rendering Template: {$templateFile->path()->filepath}",
                $exception->getCode() > 0 ? $exception->getCode() : 500,
                $exception
            );
        }
    }

    /**
     * @param File $templateFile
     * @param array $bindings
     * @param callable|null $filter
     * @return string
     * @throws RenderingException
     */
    public function renderFile(File $templateFile, array $bindings = [], callable $filter = null): string
    {
        $templateResolver = new TemplateResolver($this->config, $this->cache, $this->functions, $this->processors, $this->templateDir);
        $content = $templateResolver->render($templateFile, $bindings);

        if ($filter !== null) {
            $content = $filter($content, $this);
        }

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
     * @throws FileSystemRuntimeException
     */
    public static function getTemplateFile(Directory $baseDir, ?Directory $relativeDir, string $filename, string $extension): ?File
    {
        /** @var Directory[] $dirs */
        $dirs = array_filter([$baseDir, $relativeDir], static function (?Directory $dir): bool {
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
