<?php

namespace ricwein\Templater\Engine;

use Countable;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException as FileSystemUnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Helper\PathFinder;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Config;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use Traversable;

class CoreFunctions
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }


    /**
     * @return BaseFunction[]
     */
    public function get(): array
    {
        $exposeFunctions = [
            'dump' => [$this, 'dump'],
            'constant' => [$this, 'mapConstant'],
            'not' => [$this, 'not'],

            'abs' => 'abs',
            'round' => [$this, 'round'],
            'range' => [$this, 'range'],
            'max' => 'max',
            'min' => 'min',

            'escape' => [$this, 'escape'],
            'e' => [$this, 'escape'],

            'even' => [$this, 'isEven'],
            'odd' => [$this, 'isOdd'],
            'defined' => [$this, 'isDefined'],
            'undefined' => [$this, 'isUndefined'],
            'empty' => [$this, 'isEmpty'],
            'iterable' => [$this, 'isIterable'],
            'string' => 'is_string',
            'bool' => 'is_bool',
            'float' => 'is_float',
            'int' => 'is_int',
            'numeric' => 'is_numeric',
            'array' => 'is_array',
            'object' => 'is_object',
            'scalar' => 'is_scalar',
            'instanceof' => [$this, 'isInstanceof'],

            'count' => 'count',
            'length' => [$this, 'length'],
            'sum' => 'array_sum',
            'keys' => 'array_keys',
            'values' => 'array_values',
            'column' => 'array_column',
            'sorted' => [$this, 'sort'],
            'merge' => 'array_merge',
            'flip' => 'array_flip',
            'reverse' => 'array_reverse',
            'flat' => [$this, 'flat'],
            'first' => [$this, 'first'],
            'last' => [$this, 'last'],

            'join' => [$this, 'join'],
            'split' => [$this, 'split'],
            'slice' => [$this, 'slice'],

            'lower' => 'strtolower',
            'upper' => 'strtoupper',
            'title' => [$this, 'strtotitle'],
            'capitalize' => [$this, 'capitalize'],
            'trim' => 'trim',
            'rtrim' => 'rtrim',
            'ltrim' => 'ltrim',
            'nl2br' => 'nl2br',
            'striptags' => 'strip_tags',
            'replace' => [$this, 'replace'],
            'spaceless' => [$this, 'spaceless'],

            'json_encode' => 'json_encode',
            'json_decode' => 'json_decode',
            'url_encode' => 'rawurlencode',
            'url_decode' => 'rawurldecode',

            'format' => 'sprintf',
            'date' => [$this, 'date'],

            'file' => [$this, 'getFile'],
            'directory' => [$this, 'getDirectory'],
        ];

        $functions = [];
        foreach ($exposeFunctions as $name => $callable) {
            $functions[] = new BaseFunction($name, $callable);
        }
        return $functions;
    }

    public function join(array $array, string $glue): string
    {
        return implode($glue, $array);
    }

    public function split(string $string, string $delimiter = '', ?int $limit = null): array
    {
        if (!empty($delimiter) && is_numeric($delimiter) && (strlen($delimiter) === strlen((string)(int)$delimiter))) {
            return str_split($string, (int)$delimiter);
        } else if (!empty($delimiter) && is_string($delimiter)) {
            return $limit !== null ? explode($delimiter, $string, $limit) : explode($delimiter, $string);
        }

        return str_split($string);
    }

    /**
     * @inheritDoc
     * @param string|array $input
     * @return string|array
     * @throws UnexpectedValueException
     */
    public function slice($input, int $start, ?int $length = null, bool $preserveKeys = false)
    {
        if (is_array($input)) {
            return array_slice($input, $start, $length, $preserveKeys);
        } elseif (is_string($input)) {
            return mb_substr($input, $start, $length);
        }

        throw new UnexpectedValueException(sprintf("Invalid Datatype for slice() input: %s", gettype($input)), 500);
    }

    public function escape($string): string
    {
        if (is_string($string) || (is_object($string) && method_exists($string, '__toString'))) {
            return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE);
        }

        return (string)$string;
    }

    public function range($start, $end, int $steps = 1)
    {
        return range($start, $end, $steps);
    }

    public function length($variable): int
    {
        if (is_array($variable) || (is_object($variable) && $variable instanceof Countable)) {
            return count($variable);
        }

        if (is_string($variable) || (is_object($variable) && method_exists($variable, '__toString'))) {
            return strlen((string)$variable);
        }

        if (is_object($variable) && $variable instanceof Traversable) {
            return iterator_count($variable);
        }

        return 0;
    }

    public function dump(...$variables): string
    {
        if (!$this->config->debug) {
            return '';
        }

        $result = [];
        foreach ($variables as $variable) {
            ob_start();
            var_dump($variable);
            $result[] = sprintf('<pre><code>%s</code></pre>', trim(ob_get_clean()));
        }

        return implode(PHP_EOL, $result);
    }

    public function flat(array $array): array
    {
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator((array)$array));
        $flattenedArray = [];
        foreach ($it as $innerValue) {
            $flattenedArray[] = $innerValue;
        }
        return $flattenedArray;
    }

    public function first(array $array)
    {
        return $array[array_key_first($array)];
    }

    public function last(array $array)
    {
        return $array[array_key_last($array)];
    }

    public function isEmpty($var): bool
    {
        if (is_string($var)) {
            return empty(trim($var));
        }
        return empty($var);
    }

    public function isInstanceof(string $class, object $var): bool
    {
        return $var instanceof $class;
    }

    public function capitalize(string $string): string
    {
        return ucfirst(strtolower($string));
    }

    public function strtotitle(string $string): string
    {
        return mb_convert_case($string, MB_CASE_TITLE);
    }

    /**
     * @inheritDoc
     * @throws UnexpectedValueException
     */
    public function date($time, string $format, ?string $modifier = null): string
    {
        switch (true) {

            case $time === null || strtolower(trim($time)) === 'now':
                if ($modifier !== null) {
                    return date($format, strtotime($modifier));
                }
                return date($format);

            case is_string($time) && $modifier !== null:
                return date($format, strtotime(trim("{$time} {$modifier}")));

            case is_string($time) && $modifier === null:
                return date($format, strtotime($time));

            case is_int($time) && $modifier !== null:
                return date($format, strtotime("{$modifier}", $time));

            case is_int($time) && $modifier === null:
                return date($format, $time);

        }

        throw new UnexpectedValueException(sprintf("Invalid Datatype for date() input: %s", gettype($time)), 500);
    }

    public function sort(array $array, ?string $compareFunction = null): array
    {
        if ($compareFunction === null) {
            asort($array);
        } else {
            uasort($array, $compareFunction);
        }

        return $array;
    }

    public function replace(string $subject, $search, $replace = null): string
    {
        if ($replace !== null) {
            return str_replace($search, $replace, $subject);
        }
        return str_replace(array_keys($search), array_values($search), $subject);
    }

    public function spaceless(string $content): string
    {
        return trim(preg_replace('/>\s+</', '><', $content));
    }

    /**
     * @param float $value
     * @param int $precision
     * @param string $method
     * @return int
     * @throws UnexpectedValueException
     */
    public function round(float $value, int $precision = 0, $method = 'common'): int
    {
        switch (strtolower($method)) {
            case 'common':
                return round($value, $precision);
            case 'floor':
                return floor($value * pow(10, $precision)) / pow(10, $precision);
            case 'ceil':
                return ceil($value * pow(10, $precision)) / pow(10, $precision);
        }

        throw new UnexpectedValueException("Invalid rounding method: {$method}", 500);
    }

    public function mapConstant(string $constant, ?object $class = null)
    {
        if ($class !== null) {
            $constant = get_class($class) . '::' . $constant;
        }

        return constant($constant);
    }

    /**
     * @inheritDoc
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    public function getFile(string ...$path): File
    {
        $storage = PathFinder::try([
            new Storage\Disk(...$path),
            new Storage\Disk\Current(...$path),
        ]);

        return new File($storage, Constraint::STRICT);
    }

    /**
     * @inheritDoc
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    public function getDirectory(string ...$path): Directory
    {
        $storage = new Storage\Disk($path);
        return new Directory($storage, Constraint::STRICT);
    }

    public function isEven($number): bool
    {
        return $number % 2 === 0;
    }

    public function isOdd($number): bool
    {
        return !$this->isEven($number);
    }

    public function isDefined($value): bool
    {
        return isset($value) && $value !== null;
    }

    public function isUndefined($value): bool
    {
        return !$this->isDefined($value);
    }

    public function isIterable($value): bool
    {
        return is_iterable($value);
    }

    public function not($value): bool
    {
        return !$value;
    }
}
