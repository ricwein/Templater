<?php


namespace ricwein\Templater\Resolver;


class Symbol
{
    public const TYPE_NULL = 0;

    public const TYPE_VARIABLE = 1;
    public const TYPE_STRING = 2;

    public const TYPE_FLOAT = 4;
    public const TYPE_INT = 8;
    public const TYPE_BOOL = 16;

    public const TYPE_OBJECT = 32;
    public const TYPE_ARRAY = 64;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var int
     */
    private int $type;

    public function __construct($value, int $type = null)
    {
        $this->value = $value;

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
                    $this->type = self::TYPE_VARIABLE;
                    break;
            }
        }
    }

    public function value()
    {
        return $this->value;
    }

    public function type(): int
    {
        return $this->type;
    }

    public function is(int $type): bool
    {
        return $this->type === $type;
    }
}
