<?php


namespace ricwein\Templater\Resolver;


use ricwein\Templater\Exceptions\RuntimeException;

class Symbol
{
    public const TYPE_NULL = 'NULL';

    public const TYPE_STRING = 'string';
    public const TYPE_FLOAT = 'float';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';

    public const TYPE_OBJECT = 'object';
    public const TYPE_ARRAY = 'array';

    public const ANY_SCALAR = [self::TYPE_STRING, self::TYPE_FLOAT, self::TYPE_INT, self::TYPE_BOOL];
    public const ANY_DEFINABLE = [self::TYPE_FLOAT, self::TYPE_INT, self::TYPE_BOOL, self::TYPE_ARRAY];
    public const ANY_ACCESSIBLE = [self::TYPE_OBJECT, self::TYPE_ARRAY];
    public const ANY_KEYPATH_PART = [self::TYPE_STRING, self::TYPE_INT];
    public const ANY_NUMERIC = [self::TYPE_INT, self::TYPE_FLOAT];

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var string
     */
    private string $type;

    /**
     * @var bool
     */
    private bool $interruptKeyPath;

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
        } else {
            switch (true) {

                case is_int($value):
                    $this->type = self::TYPE_INT;
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
    }

    public function value(bool $trimmed = false)
    {
        if ($trimmed && $this->type === static::TYPE_STRING) {
            return trim($this->value);
        }

        return $this->value;
    }

    public function interruptKeyPath(): bool
    {
        return $this->interruptKeyPath;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function is($type): bool
    {
        if (is_array($type)) {
            return in_array($this->type, $type, true);
        }
        return $this->type === $type;
    }
}
