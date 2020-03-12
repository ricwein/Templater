<?php

namespace ricwein\Templater\Resolver;

/**
 * A state aware object depth-first search iterator.
 * @package ricwein\Templater\Engine
 */
class KeypathFinder
{
    /**
     * @var mixed
     */
    private $source;

    /**
     * @var mixed
     */
    private $current;

    /**
     * @var string[]
     */
    private array $path = [];

    public function __construct($source = [])
    {
        $this->source = $source;
        $this->reset();
    }

    public function reset(): self
    {
        $this->current = $this->source;
        $this->path = [];
        return $this;
    }

    public function next($key): bool
    {
        switch (true) {
            case is_array($this->current) && (is_string($key) || is_numeric($key)) && array_key_exists($key, $this->current):
                $this->current = $this->current[$key];
                $this->path[] = $key;
                return true;

            case is_object($this->current) && (property_exists($this->current, $key) || isset($this->current->$key)):
                $this->current = $this->current->$key;
                $this->path[] = $key;
                return true;
        }

        return false;
    }

    public function get()
    {
        return $this->current;
    }

    public function getPath(): array
    {
        return $this->path;
    }
}
