<?php

namespace ricwein\Templater\Engine;

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
            'abs' => 'abs',
            'round' => [$this, 'round'],
            'join' => [$this, 'join'],
            'split' => [$this, 'split'],
            'range' => [$this, 'split'],
            'dump' => [$this, 'dump'],
            'lower' => 'strtolower',
            'upper' => 'strtoupper',
            'title' => [$this, 'strtotitle'],
            'capitalize' => [$this, 'capitalize'],
            'trim' => 'trim',
            'nl2br' => 'nl2br',
            'striptags' => 'strip_tags',
            'json_encode' => 'json_encode',
            'json_decode' => 'json_decode',
            'constant' => [$this, 'mapConstant'],
            'replace' => [$this, 'replace'],
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
            'empty' => [$this, 'empty'],
            'format' => 'sprintf',
            'date' => [$this, 'date'],
            'url_encode' => 'rawurlencode',
            'url_decode' => 'rawurldecode',
            'file' => [$this, 'getFile'],
            'directory' => [$this, 'getDirectory'],
        ];

        $functions = [
            new BaseFunction('escape', [$this, 'escape'], 'e'),
        ];

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

    public function escape(string $string): string
    {
        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    public function range($start, $end, int $steps = 1)
    {
        return range($start, $end, $steps);
    }

    public function length($variable): int
    {
        if (is_array($variable) || (is_object($variable) && $variable instanceof \Countable)) {
            return count($variable);
        }

        if (is_string($variable) || (is_object($variable) && method_exists($variable, '__toString'))) {
            return strlen((string)$variable);
        }

        if (is_object($variable) && $variable instanceof \Traversable) {
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

    public function empty($var): bool
    {
        return empty($var);
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
    public function date($time, string $format, ?string $modifier): string
    {
        switch (true) {

            case $time === null || strtolower(trim($time)) === 'now':
                if ($modifier !== null) {
                    return date($format, strtotime($modifier));
                }
                return date($format);

            case is_string($time):
            case is_int($time):
                return date($format, strtotime(trim("{$time} {$modifier}")));

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
}
