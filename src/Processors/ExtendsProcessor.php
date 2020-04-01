<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;

class ExtendsProcessor extends IncludeProcessor
{
    public static function startKeyword(): string
    {
        return 'extends';
    }

    /**
     * @inheritDoc
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof HeadOnlySymbols) {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $headTokens = $this->symbols->headTokens();
        $filenameToken = array_shift($headTokens);
        $file = $this->getFile($filenameToken, $context);

        // create new sub-only context
        $subContext = new Context(
            $file,
            $context->bindings,
            $context->functions,
            $context->environment
        );

        $lines = $this->templater->renderFile($subContext);

        // write back of sub-context into main-context
        $context->bindings = $subContext->bindings;
        $context->environment = $subContext->environment;

        return [$lines];
    }
}
