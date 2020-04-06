<?php

namespace ricwein\Templater\Engine;

use Countable;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException as FileSystemUnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Helper\PathFinder;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Config;
use ricwein\Templater\Exceptions\RuntimeException as TemplateRuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Resolver\TemplateResolver;
use Traversable;
use function foo\func;

class CoreFunctions
{
    private Config $config;

    private ?Context $context = null;
    private ?TemplateResolver $templateResolver = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function setContext(Context $context): void
    {
        $this->context = $context;
    }

    public function setTemplateResolver(TemplateResolver $templateResolver): void
    {
        $this->templateResolver = $templateResolver;
    }

    /**
     * @return BaseFunction[]
     */
    public function get(): array
    {
        /** @var array<string, callable> $exposeFunctions */
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

            'count' => [$this, 'count'],
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
            'removeemptylines' => [$this, 'removeEmptyLines'],
            'remove_empty_lines' => [$this, 'removeEmptyLines'],

            'json_encode' => 'json_encode',
            'json_decode' => 'json_decode',
            'url_encode' => 'rawurlencode',
            'url_decode' => 'rawurldecode',

            'format' => 'sprintf',
            'date' => [$this, 'date'],

            'file' => [$this, 'getFile'],
            'directory' => [$this, 'getDirectory'],
            'command' => [$this, 'getCommand'],
            'cli' => [$this, 'getCommand'],

            'block' => [$this, 'getBlock']
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

    public function count($array): int
    {
        if (is_countable($array)) {
            return count($array);
        }

        if ($array instanceof Traversable) {
            return iterator_count($array);
        }

        return 0;
    }

    public function split(string $string, string $delimiter = '', ?int $limit = null): array
    {
        if (!empty($delimiter) && is_numeric($delimiter) && (strlen($delimiter) === strlen((string)(int)$delimiter))) {
            return str_split($string, (int)$delimiter);
        }

        if (!empty($delimiter) && is_string($delimiter)) {
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
        }

        if (is_string($input)) {
            return mb_substr($input, $start, $length);
        }

        throw new UnexpectedValueException(sprintf('Invalid Datatype for slice() input: %s', gettype($input)), 500);
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

    public function removeEmptyLines(string $content, int $consecutivelyLines = 1): string
    {
        $lines = explode(PHP_EOL, $content);

        /** @var bool[] $prevLines */
        $prevLines = array_fill(0, $consecutivelyLines, true);
        $lines = array_filter($lines, static function (string $line) use (&$prevLines): string {
            $prevLines[] = empty(trim($line));
            array_shift($prevLines);

            foreach ($prevLines as $prevLine) {
                if (!$prevLine) {
                    return true;
                }
            }
            return false;
        });

        return implode(PHP_EOL, $lines);
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
                return floor($value * (10 ** $precision)) / (10 ** $precision);
            case 'ceil':
                return ceil($value * (10 ** $precision)) / (10 ** $precision);
        }

        throw new UnexpectedValueException("Invalid rounding method: {$method}", 500);
    }

    public function mapConstant(string $constant, ?object $class = null)
    {
        if ($class !== null) {
            $constant = sprintf('%s::%s', get_class($class), $constant);
        }

        return constant($constant);
    }

    /**
     * @param array<string|Storage>|string|Storage $path
     * @return Storage|null
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    private function fetchStoragePath($path): ?Storage
    {
        $paths = [];

        if (is_array($path)) {

            if ($this->context !== null) {
                $paths[] = new Storage\Disk($this->context->template()->path()->directory, ...$path);
            }
            $paths[] = new Storage\Disk(...$path);

        } else {

            if ($this->context !== null) {
                $paths[] = new Storage\Disk($this->context->template()->path()->directory, $path);
            }
            $paths[] = new Storage\Disk($path);

        }

        try {
            return PathFinder::try($paths);
        } catch (FileNotFoundException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     * @param array<string|Storage>|string|Storage|null $path
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    public function getFile($path = null, int $constraints = Constraint::STRICT): ?File
    {
        if ($path === null && $this->context !== null) {
            return new File(clone $this->context->template()->storage(), $constraints);
        }

        if ($path !== null && null !== $storage = $this->fetchStoragePath($path)) {
            return new File($storage, $constraints);
        }

        return null;
    }

    /**
     * @inheritDoc
     * @param array<string|Storage>|string|Storage|null $path
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    public function getDirectory($path = null, int $constraints = Constraint::STRICT): Directory
    {
        if ($path === null && $this->context !== null) {
            return new Directory($this->context->template()->directory($constraints)->storage(), $constraints);
        }

        if ($path !== null && null !== $storage = $this->fetchStoragePath($path)) {
            return new Directory($storage, $constraints);
        }

        return null;
    }

    /**
     * @inheritDoc
     * @param array<string|Storage>|string|Storage|null $path
     * @throws AccessDeniedException
     * @throws Exception
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     */
    public function getCommand($binPath, $path = null, int $constraints = Constraint::STRICT): Directory
    {
        if ($path === null && $this->context !== null) {
            $storage = $this->context->template()->directory($constraints)->storage();
            if ($storage instanceof Storage\Disk) {
                return new Directory\Command($storage, $constraints, $binPath);
            }
        }

        if ($path !== null && (null !== $storage = $this->fetchStoragePath($path)) && $storage instanceof Storage\Disk) {
            return new Directory\Command($storage, $constraints, $binPath);
        }

        return null;
    }

    public function isEven($number): bool
    {
        return $number % 2 === 0;
    }

    public function isOdd($number): bool
    {
        return $number % 2 !== 0;
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

    /**
     * @inheritDoc
     * @param string $blockName
     * @return string
     * @throws TemplatingException
     * @throws UnexpectedValueException
     * @throws TemplateRuntimeException
     */
    public function getBlock(string $blockName): string
    {
        $blockName = trim($blockName);

        if ($this->context === null) {
            throw new TemplatingException(sprintf('Unable to fetch block for name: %s. Missing a proper context.', $blockName), 500);
        }

        if (null !== $block = $this->context->environment->getResolvedBlock($blockName)) {
            return $block;
        }

        if ($this->templateResolver !== null && null !== $block = $this->context->resolveBlock($blockName, $this->templateResolver)) {
            return $block;
        }

        if (null !== $this->context->environment->getBlockVersions($blockName)) {
            throw new TemplatingException(sprintf('Unable to fetch block for name: %s. Unable to resolve block in Runtime.', $blockName), 500);
        }

        throw new TemplatingException(sprintf('Unable to fetch block for name: %s. Unknown block.', $blockName), 500);
    }
}
