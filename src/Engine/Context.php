<?php

namespace ricwein\Templater\Engine;

use ricwein\FileSystem\File;
use ricwein\Templater\Resolver\Resolver;

class Context
{
    private File $template;
    public array $bindings;
    public array $functions;
    public array $environment;

    public function __construct(File $template, array $bindings = [], array $functions = [], array $environment = [])
    {
        $this->bindings = $bindings;
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
