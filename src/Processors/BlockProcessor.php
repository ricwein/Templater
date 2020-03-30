<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;

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
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        return $this->templater->resolveSymbols($this->symbols->content, $context);
    }
}
