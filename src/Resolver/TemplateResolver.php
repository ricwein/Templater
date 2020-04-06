<?php

namespace ricwein\Templater\Resolver;

use Exception;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\File;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Templater;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Templater\Processors;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

class TemplateResolver extends Resolver
{
    private Config $config;
    protected ?ExtendedCacheItemPoolInterface $cache;
    protected Directory $templateDir;

    /**
     * @var string[]
     */
    private array $processors;

    /**
     * @var array<string, BaseFunction>
     */
    private array $functions;
    private CoreFunctions $coreFunctions;


    public function __construct(Config $config, ?ExtendedCacheItemPoolInterface $cache, array $functions, array $processors, Directory $templateDir)
    {
        $this->config = $config;
        $this->cache = $cache;
        $this->functions = $functions;
        $this->processors = $processors;
        $this->templateDir = $templateDir;

        // load core functions
        $this->coreFunctions = new CoreFunctions($config);
        foreach (($this->coreFunctions)->get() as $function) {
            $this->functions[$function->getName()] = $function;
        }


        $this->tokenizer = new Tokenizer([], [
            new Block('{#', '#}', false, true), // comment
            new Block('{{', '}}', false, true), // variable or function call
            new Block('{%', '%}', false, true), // statement
        ], [
            'maxDepth' => 0,
            'disableAutoTrim' => true,
        ]);
    }

    public function getCache(): ?ExtendedCacheItemPoolInterface
    {
        return $this->cache;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * @param File $templateFile
     * @param array $bindings
     * @return string
     * @throws RenderingException
     */
    public function render(File $templateFile, array $bindings): string
    {
        return $this->renderFile(new Context(
            $templateFile,
            $bindings,
            $this->functions
        ));
    }

    /**
     * @param Context $context
     * @return string
     * @throws RenderingException
     * @internal
     */
    public function renderFile(Context $context): string
    {
        $this->coreFunctions->setContext($context);

        // add current global config to context-bindings to allow usage in templates
        $context->bindings = array_replace_recursive($context->bindings, ['config' => $this->config->asArray()]);
        $lineno = 1;

        try {
            $templateContent = $context->template()->read();
            $tokenStream = $this->tokenizer->tokenize($templateContent, $lineno);

            $lines = $this->resolveStream($tokenStream, $context, $lineno);
            return implode('', $lines);

        } catch (RenderingException $exception) {
            if ($exception->getTemplateFile() === null) {
                $exception->setTemplateFile($context->template());
            }
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering Template: {$exception->getMessage()}", $exception->getCode() > 0 ? $exception->getCode() : 400, $exception, $context->template(), $lineno);
        }
    }

    /**
     * processes a whole token-stream (complete template file)
     * @param TokenStream $stream
     * @param Context $context
     * @param int|null &$lineno
     * @return string[]
     * @throws RenderingException
     */
    private function resolveStream(TokenStream $stream, Context $context, ?int &$lineno = null): array
    {
        $lines = [];

        while ($token = $stream->next()) {

            // expose current line number for better exception messages
            if ($lineno !== null) {
                $lineno = $token->line();
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
     * @return string[]
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
                    'Unexpected Symbol of type: %s.',
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

            $value = $context->expressionResolver()->resolve(trim($content), $token->line());
            return $this->asPrintable($value, $content);

        }

        if ($token->block()->is('{#', '#}')) {

            if (!$this->config->stripComments) {
                return sprintf('<!-- %s -->', $content);
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
            if ($exception->getTemplateFile() === null) {
                $exception->setTemplateFile($context->template());
            }
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering Template: {$exception->getMessage()}", $exception->getCode() > 0 ? $exception->getCode() : 400, $exception, $context->template(), $token->line());
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
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
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
        return Templater::getTemplateFile($this->templateDir, $relativeDir, $filename, $this->config->fileExtension);
    }
}
