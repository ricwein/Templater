<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

/**
 * provide base worker
 */
abstract class Processor
{
    protected string $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function getResult(): string
    {
        return $this->content;
    }

    abstract public function process(): self;
}
