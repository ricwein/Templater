<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Tokenizer\Result\BaseToken;

class BlockProcessor extends Processor
{
    public static function startKeyword(): string
    {
        return 'block';
    }

    protected static function endKeyword(): ?string
    {
        return 'endblock';
    }

    /**
     * @inheritDoc
     * @param Context $context
     * @return array
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof BlockSymbols) {
            throw new RuntimeException(sprintf('Unsupported Processor-Symbols of type: %s', substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        // get current block name
        $blockName = trim(implode('', $this->symbols->headTokens()));


        $context->environment->addBlock($blockName, $this->symbols->content);
        if (null === $resolved = $context->resolveBlock($blockName, $this->templateResolver)) {
            return [];
        }

        return [$resolved];
    }
}
