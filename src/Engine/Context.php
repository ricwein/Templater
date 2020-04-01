<?php

namespace ricwein\Templater\Engine;

use ricwein\FileSystem\File;
use ricwein\Templater\Resolver\Resolver;

class Context
{
    private File $template;
    public array $bindings;
    /**
     * @var BaseFunction[]
     */
    public array $functions;
    public array $environment;

    /**
     * Context constructor.
     * @param File $template
     * @param array $bindings
     * @param BaseFunction[] $functions
     * @param array $environment
     */
    public function __construct(File $template, array $bindings = [], array $functions = [], array $environment = [])
    {
        $this->bindings = array_replace_recursive($bindings, ['template' => ['file' => $template]]);
        $this->functions = $functions;
        $this->template = $template;
        $this->environment = $environment;
    }

    public function template(): File
    {
        return $this->template;
    }

    public function resolver(): Resolver
    {
        return new Resolver($this->bindings, $this->functions);
    }
}
