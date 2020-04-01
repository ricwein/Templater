<?php

namespace ricwein\Templater;

use ricwein\Templater\Exceptions\InvalidArgumentException;
use ricwein\Templater\Exceptions\UnexpectedValueException;

/**
 * Template Engine Configuration Class
 * @package ricwein\Templater
 * @property-read bool debug
 * @property-read int cacheDuration
 * @property-read bool cacheBusterEnabled
 * @property-read string fileExtension
 * @property-read bool stripComments
 * @property-read string|null templateDir
 */
class Config
{
    // settings with default values
    protected bool $debug = false;
    protected int $cacheDuration = 3600;
    protected bool $cacheBusterEnabled = true;
    protected string $fileExtension = ".html.twig";
    protected bool $stripComments = true;

    // settings which must be set by the user
    protected ?string $templateDir = null;

    /**
     * Config constructor.
     * @param array $config
     * @throws UnexpectedValueException
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {

            if (!property_exists($this, $key)) {
                continue;
            }

            if ($this->{$key} === null || gettype($this->{$key}) === gettype($value)) {
                $this->{$key} = $value;
                continue;
            }

            throw new UnexpectedValueException(sprintf(
                "Type Mismatch for Config property '%s'. Expected type: %s but got: %s",
                $key,
                is_object($this->{$key}) ? sprintf('class(%s)', get_class($this->{$key})) : gettype($this->{$key}),
                is_object($value) ? sprintf('class(%s)', get_class($value)) : gettype($value),
            ), 500);

        }
    }

    /**
     * @param string $name
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function __get(string $name)
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }

        throw new InvalidArgumentException("Templater Config property for key '{$name}' not found", 500);
    }

    public function asArray(): array
    {
        return get_object_vars($this);
    }
}
