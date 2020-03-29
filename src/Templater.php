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
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

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
    private Tokenizer $tokenizer;

    /**
     * @var string[]
     */
    private array $processors = [];

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
            throw new RuntimeException("Initialization of the Templater class requires Config::\$templateDir to be set, but is not.", 500);
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

        // load core functions
        foreach ((new CoreFunctions($this->config))->get() as $function) {
            $this->addFunction($function);
        }

        // setup core processors
        $this->processors = [
            Processors\BlockProcessor::class,
            Processors\IncludeProcessor::class,
            Processors\IfProcessor::class,
            Processors\ForLoopProcessor::class,
        ];

        $this->tokenizer = new Tokenizer([], [
            new Block('{#', '#}', false, true), // comment
            new Block('{{', '}}', false, true), // variable or function call
            new Block('{%', '%}', false, true), // statement
        ], [
            'maxDepth' => 0,
            'disableAutoTrim' => true,
        ]);
    }

    public function addFunction(BaseFunction $function): self
    {
        $this->functions[$function->getName()] = $function;
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
            $templateFile = $this->getRelativeTemplateFile(null, $templateName);
        } catch (Exception $exception) {
            throw new FileNotFoundException("Error opening template: {$templateName}.", 404, $exception);
        }

        if ($templateFile === null) {
            throw new FileNotFoundException("No template file found for: {$templateName}.", 404);
        }

        try {
            $context = new Context(
                $templateFile,
                $bindings,
                $this->functions,
            );

            $content = $this->renderFile($context);

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new TemplatingException(
                "Error rendering Template: {$templateFile->path()->filepath}",
                $exception->getCode() > 0 ? $exception->getCode() : 500,
                $exception
            );
        }

        if ($filter !== null) {
            $content = call_user_func_array($filter, [$content, $this]);
        }

        return $content;
    }

    /**
     * @param Context $context
     * @return string
     * @throws RenderingException
     */
    public function renderFile(Context $context): string
    {
        // add current global config to context-bindings to allow usage in templates
        $context->bindings = array_replace_recursive($context->bindings, ['config' => $this->config->asArray()]);
        $line = 0;

        try {
            $templateContent = $context->template()->read();
            $tokenStream = $this->tokenizer->tokenize($templateContent);

            $lines = $this->resolveStream($tokenStream, $context, $line);
            return implode('', $lines);

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering template.", 500, $exception, $context->template(), $line);
        }
    }

    /**
     * processes a whole token-stream (complete template file)
     * @param TokenStream $stream
     * @param Context $context
     * @param int|null &$line
     * @return string[]
     * @throws RenderingException
     */
    private function resolveStream(TokenStream $stream, Context $context, ?int &$line = null): array
    {
        $lines = [];

        while ($token = $stream->next()) {

            // expose current line number for better exception messages
            if ($line !== null) {
                $line = $token->line();
            }

            if ($token instanceof Token) {
                $lines[] = $token->content();
            } elseif ($token instanceof BlockToken) {
                $lines = array_merge($lines, $this->resolveLineTokens($token, $context, $stream));
            }
        }

        return $lines;
    }

    /**
     * resolve a collection of symbols, mostly a subset of a template,
     * only accepts Tokens, simple BlockTokens (comments, vars) and pre-parsed Processors
     * @param Processors\Processor[]|BaseToken[] $symbols
     * @param Context $context
     * @return array
     * @throws RenderingException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function resolveSymbols(array $symbols, Context $context): array
    {
        $lines = [];

        foreach ($symbols as $symbol) {

            if ($symbol instanceof Processors\Processor) {

                $lines = array_merge($lines, $symbol->process($context));

            } elseif ($symbol instanceof Token) {

                $lines[] = $symbol->content();

            } elseif ($symbol instanceof BlockToken && null !== $line = $this->resolveToken($symbol, $context)) {

                $lines[] = $line;

            } else {

                throw new UnexpectedValueException(sprintf(
                    "Unexpected Symbol of type: %s.",
                    is_object($symbol) ? sprintf('class (%s)', get_class($symbol)) : gettype($symbol),
                ), 500);
            }
        }

        return $lines;
    }

    /**
     * processes a single simple token (comment or var)
     * @param BlockToken $token
     * @param Context $context
     * @return string|null
     * @throws RuntimeException
     */
    private function resolveToken(BlockToken $token, Context $context): ?string
    {
        $content = $token->content();

        if ($token->block()->is('{{', '}}')) {

            $value = $context->resolver()->resolve($content);
            return $this->asPrintable($value, $content);

        }

        if ($token->block()->is('{#', '#}')) {

            if (!$this->config->stripComments) {
                return sprintf("<!-- %s -->", $content);
            }
            return '';

        }

        return null;
    }

    /**
     * @param BlockToken|Processors\Processor $token
     * @param Context $context
     * @param TokenStream $stream
     * @return string[]
     * @throws RenderingException
     */
    private function resolveLineTokens(BlockToken $token, Context $context, TokenStream $stream): array
    {
        /** @var string[] $blocks */
        $lines = [];

        try {

            if (null !== $line = $this->resolveToken($token, $context)) {

                $lines[] = $line;

            } elseif ($token->block()->is('{%', '%}')) {

                $statement = new Statement($token, $context);
                $lines = array_merge($lines, $this->resolveProcessorToken($statement, $stream)->process($context));
            }

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering template.", 500, $exception, $context->template(), $token->line());
        }

        return $lines;
    }

    /**
     * @param Statement $statement
     * @param TokenStream $stream
     * @return Processors\Processor
     * @throws RenderingException
     * @throws RuntimeException
     */
    public function resolveProcessorToken(Statement $statement, TokenStream $stream): Processors\Processor
    {
        foreach ($this->processors as $processorClassName) {
            if ($processorClassName::isQualified($statement)) {

                /** @var Processors\Processor $processor */
                $processor = new $processorClassName($this);

                return $processor->parse($statement, $stream);
            }
        }

        throw new RuntimeException(sprintf("Found unsupported processor statement: %s", implode(' ', $statement->keywordTokens())), 500);
    }

    /**
     * @param mixed $value
     * @param string $path
     * @return string
     * @throws RuntimeException
     */
    private function asPrintable($value, string $path): string
    {
        // check for return type
        if ($value === null) {
            return '';
        } elseif (is_string($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            return $value;
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if ($this->config->debug) {
            $type = is_object($value) ? sprintf('class (%s)', get_class($value)) : gettype($value);
            $hrValue = str_replace([PHP_EOL, ' '], '', print_r($value, true));
            throw new RuntimeException(sprintf(
                "Unable to print non-scalar value for '%s' (type: %s | is: %s)",
                $path, $type, $hrValue,
            ), 500);
        }

        return '';

    }

    /**
     * @param File $file
     * @return string
     * @throws FileSystemRuntimeException
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
     * @param Directory|null $relativeDir
     * @param string $filename
     * @return File|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     */
    public function getRelativeTemplateFile(?Directory $relativeDir, string $filename): ?File
    {
        return static::getTemplateFile($this->templateDir, $relativeDir, $filename, $this->config->fileExtension);
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
