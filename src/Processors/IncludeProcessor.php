<?php

namespace ricwein\Templater\Processors;

use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;
use ricwein\Tokenizer\Result\BaseToken;

class IncludeProcessor extends Processor
{
    public static function startKeyword(): string
    {
        return 'include';
    }

    /**
     * @param BaseToken[] $tokens
     * @param Context $context
     * @return File
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    protected function getFile(array $tokens, Context $context): File
    {
        $firstToken = reset($tokens);
        $filename = $context->expressionResolver()->resolve(implode('', $tokens), $firstToken->line());

        /** @var File|null $file */
        if (is_string($filename)) {
            $file = $this->templateResolver->getRelativeTemplateFile($context->template()->directory(), $filename);
        } elseif ($filename instanceof File) {
            $file = $filename;
        }

        if ($file === null || !$file->isFile()) {
            throw new RuntimeException(sprintf('Include of file "%s" failed: File Not Found', $filename), 404);
        }

        return $file;
    }

    /**
     * @param BaseToken[] $tokens
     * @param Context $context
     * @return array
     * @throws RuntimeException
     * @throws RenderingException
     */
    private function parseParameters(array $tokens, Context $context): array
    {
        if (null === $keyword = array_shift($tokens)) {
            return [false, []];
        }

        if (trim($keyword) === 'only') {
            return [true, []];
        }

        if (trim($keyword) !== 'with') {
            throw new RenderingException("Invalid keyword found in include statement: {$keyword} - only 'with' and 'only' are supported.", 500, null, $context->template(), $keyword->line());
        }

        $only = false;
        if (null !== $lastKey = array_key_last($tokens)) {
            $lastKeyword = $tokens[$lastKey];
            if (trim($lastKeyword) === 'only') {
                array_pop($tokens);
                $only = true;
            }
        }

        $parameters = $context->expressionResolver()->resolve(implode('', $tokens), $keyword->line());
        if (!is_array($parameters) && !is_countable($parameters) && !is_iterable($parameters)) {
            throw new RenderingException(sprintf('Include with parameters must be an array, but is: %s', is_object($parameters) ? sprintf('class (%s)', get_class($parameters)) : gettype($parameters)), 500, null, $context->template(), $keyword->line());
        }


        return [$only, $parameters];
    }

    /**
     * @inheritDoc
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws RenderingException
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof HeadOnlySymbols) {
            throw new RuntimeException(sprintf('Unsupported Processor-Symbols of type: %s', substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $parameterTokens = $this->symbols->headTokens();
        $filenameTokens = [];
        while (true) {
            $token = $parameterTokens[array_key_first($parameterTokens)];
            if (in_array(trim($token->content()), ['with', 'only'], true)) {
                break;
            }
            $filenameTokens[] = array_shift($parameterTokens);
        }

        $file = $this->getFile($filenameTokens, $context);
        [$only, $parameters] = $this->parseParameters($parameterTokens, $context);

        // create new sub-only context
        $subContext = new Context(
            $file,
            $only ? $parameters : array_replace_recursive($context->bindings, $parameters),
            $context->functions,
            $context->environment
        );

        return [
            $this->templateResolver->renderFile($subContext)
        ];
    }
}
