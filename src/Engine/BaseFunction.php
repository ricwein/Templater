<?php

namespace ricwein\Templater\Engine;

use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

class BaseFunction
{
    private string $__name;
    private ?string $__shortName;

    /**
     * @var ReflectionParameter[]|null
     */
    private ?array $__parameters = null;
    private ?int $__requiredParameters = null;

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

    /**
     * @return array
     * @throws ReflectionException
     */
    public function getParameters(): array
    {
        if ($this->__parameters === null) {
            $this->runReflection();
        };

        return $this->__parameters;
    }

    public function getNumberOfRequiredParameters(): int
    {
        if ($this->__requiredParameters === null) {
            $this->runReflection();
        }
        return $this->__requiredParameters;
    }

    /**
     * @throws ReflectionException
     */
    private function runReflection()
    {
        $reflection = is_array($this->__function) ? new ReflectionMethod($this->__function[0], $this->__function[1]) : new ReflectionFunction($this->__function);
        $this->__parameters = $reflection->getParameters();
        $this->__requiredParameters = $reflection->getNumberOfRequiredParameters();
    }

    public function getName(): string
    {
        return $this->__name;
    }

    public function getShortName(): ?string
    {
        return $this->__shortName;
    }

    public function call(array $parameters)
    {
        return call_user_func_array($this->__function, $parameters);
    }
}
