<?php

namespace ricwein\Templater\Engine;

use ricwein\FileSystem\File;
use ricwein\Templater\Engine\Context\Environment;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Processor;
use ricwein\Templater\Resolver\ExpressionResolver;
use ricwein\Templater\Resolver\TemplateResolver;
use ricwein\Tokenizer\Result\BaseToken;

class Context
{
    private File $template;
    public array $bindings;
    /**
     * @var BaseFunction[]
     */
    public array $functions;
    public Environment $environment;

    /**
     * Context constructor.
     * @param File $template
     * @param array $bindings
     * @param BaseFunction[] $functions
     * @param Environment $environment
     */
    public function __construct(File $template, array $bindings, array $functions, ?Environment $environment = null)
    {
        $this->bindings = $bindings;
        $this->functions = $functions;
        $this->template = $template;
        $this->environment = $environment ?? new Environment();
    }

    public function copyWithTemplate(File $template): self
    {
        return new self($template, $this->bindings, $this->functions, $this->environment);
    }

    public function template(): File
    {
        return $this->template;
    }

    public function expressionResolver(): ExpressionResolver
    {
        return new ExpressionResolver(array_replace_recursive($this->bindings, [
            'template' => ['file' => $this->template()],
        ]), $this->functions);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function resolveBlock(string $blockName, TemplateResolver $templateResolver): ?string
    {
        // try to fetch version chain, early cancel and only render current block if the history is empty
        /** @var array<int, array<BaseToken|Processor>> $blockVersions */
        $blockVersions = $this->environment->getBlockVersions($blockName);

        if (null === $lastBlock = array_shift($blockVersions)) {
            return null;
        }

        if (count($blockVersions) < 1) {
            $resolved = implode('', $templateResolver->resolveSymbols($lastBlock, $this));
            $this->environment->addResolvedBlock($blockName, $resolved);
            return $resolved;
        }

        // TODO: refactor the following code to allow multiple parent() calls in one block

        $resolveContext = clone $this;
        $resolveContext->functions['parent'] = new BaseFunction('parent', static function () use (&$blockVersions, $resolveContext, $templateResolver): string {

            /** @var array<BaseToken|Processor> $lastBlock */
            if (null === $lastBlock = array_shift($blockVersions)) {
                return '';
            }
            return implode($templateResolver->resolveSymbols($lastBlock, $resolveContext));

        });

        $resolved = implode('', $templateResolver->resolveSymbols($lastBlock, $resolveContext));

        $this->environment = $resolveContext->environment;
        $this->environment->addResolvedBlock($blockName, $resolved);
        $this->bindings = $resolveContext->bindings;

        return $resolved;
    }
}
