<?php


namespace ricwein\Templater\Processors;


use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;

class UseProcessor extends IncludeProcessor
{

    public static function startKeyword(): string
    {
        return 'use';
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof HeadOnlySymbols) {
            throw new RuntimeException(sprintf('Unsupported Processor-Symbols of type: %s', substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $headTokens = $this->symbols->headTokens();
        $file = $this->getFile($headTokens, $context);


        // TODO

        return [];
    }
}
