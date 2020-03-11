<?php

namespace ricwein\Templater\Engine;

use ricwein\Templater\Exceptions\RuntimeException;
use TypeError;

class BaseFunction
{
    private string $__name;
    private ?string $__shortName;

    /**
     * @var callable
     */
    private $__function;

    /**
     * BaseFunction constructor.
     * @param string $name
     * @param callable $function
     * @param string|null $shortName
     */
    public function __construct(string $name, callable $function, ?string $shortName = null)
    {
        $this->__name = $name;
        $this->__function = $function;
        $this->__shortName = $shortName;
    }

    public function getName(): string
    {
        return $this->__name;
    }

    public function getShortName(): ?string
    {
        return $this->__shortName;
    }

    /**
     * @param array $parameters
     * @return mixed
     * @throws RuntimeException
     */
    public function call(array $parameters)
    {
        try {
            return call_user_func_array($this->__function, $parameters);
        } catch (TypeError $exception) {
            throw new RuntimeException("Function parameter type mismatch!", 500, $exception);
        }

    }
}
