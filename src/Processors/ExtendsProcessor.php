<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;
use ricwein\Tokenizer\Result\Token;

class ExtendsProcessor extends IncludeProcessor
{
    public static function startKeyword(): string
    {
        return 'extends';
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function process(Context $context): array
    {
        $baseContent = parent::process($context);
        return $baseContent;
    }
}
