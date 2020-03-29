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
use ricwein\Tokenizer\Result\Token;

class IncludeProcessor extends Processor
{
    protected static function startKeyword(): string
    {
        return 'include';
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

        $headWords = array_map(function (Token $token): string {
            return trim($token->token());
        }, $this->symbols->headTokens());

        $filename = end($headWords);
        $filename = $context->resolver()->resolve($filename);

        /** @var File|null $file */
        if (is_string($filename)) {
            $file = $this->templater->getRelativeTemplateFile($context->template()->directory(), $filename);
        } else if ($filename instanceof File) {
            $file = $filename;
        }

        if ($file === null) {
            throw new RuntimeException(sprintf('Include of file "%s" failed: File Not Found', $filename), 404);
        }

        $context = new Context(
            $file,
            $context->bindings,
            $context->functions,
            $context->environment
        );

        return [
            $this->templater->renderFile($context)
        ];
    }
}
