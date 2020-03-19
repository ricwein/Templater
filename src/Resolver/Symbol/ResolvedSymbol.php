<?php


namespace ricwein\Templater\Resolver\Symbol;


use ricwein\Templater\Exceptions\Exception;
use ricwein\Templater\Exceptions\RuntimeException;

class ResolvedSymbol extends Symbol
{
    /**
     * @var mixed
     */
    private $value;

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
}
