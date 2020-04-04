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

        $blockName = trim(implode('', $this->symbols->headTokens()));
        $context->environment->addBlock($blockName, $this->symbols->content);

        /** @var array<int, array<BaseToken|Processor>> $blockVersions */
        if (null === $blockVersions = $context->environment->getBlockVersions($blockName)) {
            return $this->templater->resolveSymbols($this->symbols->content, $context);
        }

        if (null === $lastBlock = array_shift($blockVersions)) {
            return $this->templater->resolveSymbols($this->symbols->content, $context);
        }

        $resolveContext = clone $context;
        $resolveContext->functions['parent'] = new BaseFunction('parent', function () use (&$blockVersions, $resolveContext): string {
            /** @var array<BaseToken|Processor> $lastBlock */
            if (null === $lastBlock = array_shift($blockVersions)) {
                return '';
            }
            return implode($this->templater->resolveSymbols($lastBlock, $resolveContext));
        });


        $resolved = $this->templater->resolveSymbols($lastBlock, $resolveContext);

        $context->bindings = $resolveContext->bindings;
        $context->environment = $resolveContext->environment;

        return $resolved;
    }
}
