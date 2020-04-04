<?php

namespace ricwein\Templater\Engine;

use ricwein\FileSystem\File;
use ricwein\Templater\Engine\Context\Environment;
use ricwein\Templater\Resolver\ExpressionResolver;

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
}
