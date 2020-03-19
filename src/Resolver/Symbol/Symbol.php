<?php


namespace ricwein\Templater\Resolver\Symbol;


abstract class Symbol
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
     * @var string
     */
    protected ?string $type;

    /**
     * @var bool
     */
    protected bool $interruptKeyPath;

    abstract public function value(bool $trimmed = false);

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
            return in_array($this->type(), $type, true);
        }
        return $this->type() === $type;
    }
}
