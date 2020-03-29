<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;

class BlockProcessor extends Processor
{
    protected static function startKeyword(): string
    {
        return 'block';
    }

    protected static function endKeyword(): ?string
    {
        return 'endblock';
    }

    /**
     * @inheritDoc
     */
    public function process(Context $context): array
    {
    }
}
