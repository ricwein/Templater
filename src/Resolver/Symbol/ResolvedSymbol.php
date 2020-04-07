<?php

namespace ricwein\Templater\Resolver\Symbol;

use ricwein\Templater\Exceptions\RuntimeException;

class ResolvedSymbol extends Symbol
{
    /**
     * @var mixed
     */
    private $value;

    /**
     * @var string
     */
    protected ?string $type;

    /**
     * @var bool
     */
    protected bool $interruptKeyPath;

    /**
     * Symbol constructor.
     * @param $value
     * @param bool $interruptKeyPath
     * @param string|null $type
     * @throws RuntimeException
     */
    public function __construct($value, bool $interruptKeyPath, ?string $type = null)
    {
        $this->value = $value;
        $this->interruptKeyPath = $interruptKeyPath;

        if ($type !== null) {
            $this->type = $type;
            return;
        }

        switch (true) {
            case is_int($value):
                $this->type = self::TYPE_INT;
                break;

            // valid integers can be casted as floats, in which case is_int fails
            case is_float($value) && strlen((string)$value) === strlen((string)(float)$value):
                $this->type = self::TYPE_INT;
                $this->value = (int)$value;
                break;

            case is_float($value):
                $this->type = self::TYPE_FLOAT;
                break;

            case is_string($value):
                $this->type = self::TYPE_STRING;
                break;

            case is_bool($value):
                $this->type = self::TYPE_BOOL;
                break;

            case is_object($value):
                $this->type = self::TYPE_OBJECT;
                break;

            case is_array($value):
                $this->type = self::TYPE_ARRAY;
                break;

            case $value === null:
                $this->type = self::TYPE_NULL;
                break;

            default:
                throw new RuntimeException(sprintf(
                    "Unsupported DataType %s.",
                    is_object($value) ? sprintf("class(%s)", get_class($value)) : gettype($value)
                ), 500);
                break;
        }
    }

    public function value(bool $trimmed = false)
    {
        if ($trimmed && $this->type === static::TYPE_STRING) {
            return trim($this->value);
        }

        return $this->value;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function interruptKeyPath(): bool
    {
        return $this->interruptKeyPath;
    }
}
