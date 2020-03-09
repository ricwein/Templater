<?php

namespace ricwein\Templater\Resolver;

use ricwein\Templater\Exceptions\RuntimeException;

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

    private array $path = [];

    public function __construct($source = [])
    {
        $this->source = $source;
        $this->reset();
    }

    public function reset(): self
    {
        $this->current = $this->source;
        return $this;
    }

    /**
     * @param mixed $key
     * @return self
     * @throws RuntimeException
     */
    public function next($key): self
    {
        $this->path[] = $key;
        $this->current = static::fetchValueFrom($this->current, $key, $this->path);
        return $this;
    }

    public function get()
    {
        return $this->current;
    }

    /**
     * @param $source
     * @param $key
     * @param string[] $path
     * @return mixed
     * @throws RuntimeException
     */
    private static function fetchValueFrom($source, $key, array $path)
    {
        switch (true) {
            case is_array($source) && (is_string($key) || is_numeric($key)) && array_key_exists($key, $source):
                return $source[$key];

            case is_object($source) && (property_exists($source, $key) || isset($source->$key)):
                return $source->$key;
        }

        throw new RuntimeException(sprintf(
            "Unable to resolve variable path %s. Unknown key: %s",
            implode('.', $path),
            $key
        ), 500);
    }
}
