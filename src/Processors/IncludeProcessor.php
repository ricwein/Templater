<?php


namespace ricwein\Templater\Processors;


use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;

class IncludeProcessor extends Processor
{
    /**
     * @inheritDoc
     * @param Statement $statement
     * @param TokenStream $stream
     * @return string
     * @throws RuntimeException
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     */
    public function process(Statement $statement, TokenStream $stream): string
    {
        $remaining = $statement->remainingTokens();

        if (count($remaining) !== 1) {
            throw new RuntimeException(sprintf('Invalid number of arguments for include-statement, expected 1 but got %d.', count($remaining)), 500);
        }

        $words = array_map(function (Token $token): string {
            return $token->token();
        }, $remaining);

        $filename = end($words);
        $filename = $statement->context->resolver()->resolve($filename);

        $file = $this->templater->getRelativeTemplateFile($statement->context->template()->directory(), $filename);
        if ($file === null) {
            throw new RuntimeException(sprintf('Include of file %s failed: File Not Found', $filename), 404);
        }

        $context = new Context(
            $file,
            $statement->context->bindings,
            $statement->context->functions,
            $statement->context->environment
        );

        return $this->templater->renderFile($context);
    }

    protected function startKeyword(): string
    {
        return 'include';
    }

}
