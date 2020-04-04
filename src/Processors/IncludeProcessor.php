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
use ricwein\Tokenizer\Result\Token;

class IncludeProcessor extends Processor
{
    public static function startKeyword(): string
    {
        return 'include';
    }

    /**
     * @param BaseToken $token
     * @param Context $context
     * @return File
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    protected function getFile(BaseToken $token, Context $context): File
    {
        $filename = $context->expressionResolver()->resolve((string)$token, $token->line());

        /** @var File|null $file */
        if (is_string($filename)) {
            $file = $this->templateResolver->getRelativeTemplateFile($context->template()->directory(), $filename);
        } else if ($filename instanceof File) {
            $file = $filename;
        }

        if ($file === null) {
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
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $headTokens = $this->symbols->headTokens();
        $filenameToken = array_shift($headTokens);
        $file = $this->getFile($filenameToken, $context);
        [$only, $parameters] = $this->parseParameters($headTokens, $context);

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
