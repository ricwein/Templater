<?php

namespace ricwein\Templater;

use ricwein\Templater\Exceptions\InvalidArgumentException;

/**
 * Template Engine Configuration Class
 * @package ricwein\Templater
 * @property-read bool debug
 * @property-read int cacheDuration
 * @property-read bool cacheBusterEnabled
 * @property-read string fileExtension
 * @property-read bool stripComments
 * @property-read string|null templateDir
 * @property-read string|null assetDir
 * @property-read array variables
 */
class Config
{
    // settings with default values
    private bool $debug = false;
    private int $cacheDuration = 3600;
    private bool $cacheBusterEnabled = true;
    private string $fileExtension = ".html.twig";
    private bool $stripComments = true;

    // settings which must be set by the user
    private ?string $templateDir = null;
    private ?string $assetDir = null;

    // optional variable-bindings for assets compilation (e.g. scss vars)
    private array $variables = [];

    public function __construct(array $config = [])
    {
        foreach (get_class_vars(static::class) as $name => $value) {
            if (array_key_exists($name, $config) && (gettype($value) === gettype($config[$name]) || $value === null)) {
                $this->{$name} = $config[$name];
            }
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
}
