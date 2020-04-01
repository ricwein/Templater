<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\BaseFunction;
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

        $headName = trim(implode('', $this->symbols->headTokens()));
        $resolveContext = new Context($context->template(), $context->bindings, $context->functions, $context->environment);

        if (isset($context->environment['blocks'][$headName])) {

            $parentContent = $context->environment['blocks'][$headName];
            $resolveContext->functions['parent'] = new BaseFunction('parent', function () use ($parentContent): string {
                return implode('', $parentContent);
            });
        }

        $blockContent = $this->templater->resolveSymbols($this->symbols->content, $resolveContext);
        $context->environment['blocks'][$headName] = $blockContent;
        return $blockContent;
    }
}
