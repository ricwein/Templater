<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\TokenStream;

class ExtendsProcessor extends IncludeProcessor
{
    public static function startKeyword(): string
    {
        return 'extends';
    }

    /**
     * @inheritDoc
     */
    public function parse(Statement $statement, TokenStream $stream): self
    {
        $headTokens = $statement->remainingTokens();
        $this->symbols = new BlockSymbols(static::startKeyword(), $headTokens);

        while ($token = $stream->next()) {
            if ($token instanceof BlockToken && $token->block()->is('{%', '%}')) {

                $localStatement = new Statement($token, $statement->context);

                $processor = $this->templater->resolveProcessorToken($localStatement, $stream);
                if (!$processor instanceof BlockProcessor) {
                    throw new RenderingException(sprintf('Only {%% block %%} statements and comments {# #} are allowed inside an template-extension, but got: %s', trim($token)), 500, null, $statement->context->template(), $token->line());
                }

                $this->symbols->content[] = $processor;

            } elseif (!($token instanceof BlockToken && $token->block()->is('{#', '#}')) && !empty(trim($token->content()))) {
                throw new RenderingException(sprintf('Only {%% block %%} statements and comments {# #} are allowed inside an template-extension, but got: %s', trim($token)), 500, null, $statement->context->template(), $token->line());
            }
        }

        return $this;
    }

    private function extractBlockIntoContext(array $symbols, Context $context): void
    {
        /** @var BlockProcessor $processor */
        foreach ($symbols as $processor) {

            /** @var BlockSymbols $symbol */
            $symbol = $processor->getSymbols();
            $blockName = trim(implode('', $symbol->headTokens()));

            $blockSymbols = $symbol->content;

            // store unresolved block symbols in context-environment for later resolution
            $context->environment->addBlock($blockName, $blockSymbols);
        }
    }

    /**
     * @inheritDoc
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof BlockSymbols) {
            throw new RuntimeException(sprintf('Unsupported Processor-Symbols of type: %s', substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        // fetch all local (current template) blocks
        $this->extractBlockIntoContext($this->symbols->content, $context);

        $headTokens = $this->symbols->headTokens();
        $filenameToken = array_shift($headTokens);
        $file = $this->getFile($filenameToken, $context);

        // create new sub-only context
        $subContext = $context->copyWithTemplate($file);

        // resolve current page blocks, writeback of block content into context-environments
        $baseContent = $this->templater->renderFile($subContext);

        return [$baseContent];
    }
}
