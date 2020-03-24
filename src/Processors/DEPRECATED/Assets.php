<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\AssetParser;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Resolver\Resolver;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;

/**
 * simple Template parser with Twig-like syntax
 */
class Assets extends Processor
{
    const FLAG_INLINE = 'inline';

    const FLAG_CSSMEDIA_ALL = 'media:all';
    const FLAG_CSSMEDIA_SCREEN = 'media:screen';
    const FLAG_CSSMEDIA_PRINT = 'media:print';

    const FLAG_PRELOAD = 'preload';
    const FLAG_PRELOAD_FALLBACK = 'preload:withFallback';
    const FLAG_LAZYLOAD = 'lazyload';

    protected ?ExtendedCacheItemPoolInterface $cache = null;
    private Config $config;
    private Directory $basedir;

    public function __construct(string $content, Config $config, Directory $basedir, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        parent::__construct($content);
        $this->config = $config;
        $this->basedir = $basedir;
        $this->cache = $cache;
    }

    /**
     * @param array $bindings
     * @param BaseFunction[] $functions
     * @return $this
     */
    public function process(array $bindings = [], array $functions = []): self
    {
        // include other template files
        $this->content = preg_replace_callback('/{{\s*asset\((.*)(\s*,\s*.+)?\)\s*}}/U', function (array $match) use ($bindings, $functions): string {

            $matchCount = count($match);
            if ($matchCount < 2 || $matchCount > 3) {
                throw new \RuntimeException('invalid asset matches', 500);
            }

            $flags = static::getFlags($match);
            $filename = (new Resolver($bindings, $functions))->resolve(trim($match[1]));

            return $this->buildAssetHTML($filename, $flags, $bindings);

        }, $this->content);

        return $this;
    }

    private static function getFlags(array $matches): array
    {
        if (count($matches) <= 2) {
            return [];
        }

        $flags = explode(',', $matches[2]);

        $flags = array_map(function (string $flag): string {
            return trim($flag, '"\', ');
        }, $flags);

        $flags = array_values(array_filter($flags, function (string $flag): bool {
            return !empty($flag);
        }));

        return array_unique($flags);
    }

    /**
     * @param string $assetFilename
     * @param array $flags
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws Exception
     * @throws UnexpectedValueException
     */
    private function buildAssetHTML(string $assetFilename, array $flags, array $bindings): string
    {
        $assetFile = $this->basedir->file($assetFilename);

        if ($this->config->debug && (!$assetFile->isFile() || !$assetFile->isReadable())) {
            throw new FileNotFoundException("File {$assetFile->path()->filepath} not found!", 404);
        }

        if (in_array(static::FLAG_INLINE, $flags, true)) {
            $parser = new AssetParser($this->basedir, $this->config, $this->cache);
            $lineBreak = PHP_EOL;

            switch ($assetFile->path()->extension) {

                case 'css':
                case 'scss':
                case 'sass':
                    $content = trim($parser->parse($assetFile, $bindings));
                    return empty($content) ? '' : "<style type=\"text/css\">{$lineBreak}{$content}{$lineBreak}</style>";
                    break;

                case 'js':
                    $content = trim($parser->parse($assetFile, $bindings));
                    return empty($content) ? '' : "<script>{$lineBreak}{$content}{$lineBreak}</script>";
                    break;
            }

            throw new FileNotFoundException("Asset-File Not Found: {$assetFile->path()->filepath}", 404);
        }

        $baseURL = rtrim($this->httpsGetBaseURL(), '/');

        switch ($assetFile->path()->extension) {
            case 'css':
            case 'scss':
            case 'sass':

                $filename = ltrim($assetFile->path()->filepath, '/');

                if ($this->config->cacheBusterEnabled) {
                    $fileURL = sprintf("%s/assets/%s?v=%s:%s", $baseURL, $filename,
                        $this->config->debug ? (string)$assetFile->getTime() : hash('sha256', $assetFile->getTime()),
                        $this->config->debug ? '1' : '0'
                    );
                } else {
                    $fileURL = sprintf("%s/assets/%s.css", $baseURL, $filename,);
                }

                $media = "all";
                switch (true) {
                    case in_array(static::FLAG_CSSMEDIA_ALL, $flags, true):
                        $media = "all";
                        break;

                    case in_array(static::FLAG_CSSMEDIA_SCREEN, $flags, true):
                        $media = "screen";
                        break;

                    case in_array(static::FLAG_CSSMEDIA_PRINT, $flags, true):
                        $media = "print";
                        break;
                }

                switch (true) {
                    case in_array(static::FLAG_PRELOAD, $flags, true):
                        return "<link rel=\"preload\" href=\"{$fileURL}\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\"><noscript><link rel=\"stylesheet\" href=\"{$fileURL}\"></noscript>";

                    case in_array(static::FLAG_PRELOAD_FALLBACK, $flags, true):
                        return "<link rel=\"preload\" href=\"{$fileURL}\" as=\"style\" onload=\"this.onload=null;this.rel='stylesheet'\"><link rel=\"stylesheet\" href=\"{$fileURL}\" media='{$media}'>";

                    case in_array(static::FLAG_LAZYLOAD, $flags, true):
                        return "<link href=\"{$fileURL}\" rel=\"stylesheet\" media=\"none\" onload=\"media='{$media}'\" /><noscript><link href=\"{$fileURL}\" rel=\"stylesheet\" media=\"{$media}\" /></noscript>";

                    default:
                        return "<link href=\"{$fileURL}\" rel=\"stylesheet\" media=\"{$media}\" />";

                }


            case 'js':
                $filename = ltrim(str_replace(".{$assetFile->path()->extension}", '', $assetFile->path()->filepath), '/');

                if ($this->config->cacheBusterEnabled) {
                    $fileURL = sprintf("%s/assets/%s.js?v=%s:%s", $baseURL, $filename,
                        $this->config->debug ? (string)$assetFile->getTime() : hash('sha256', $assetFile->getTime()),
                        $this->config->debug ? '1' : '0'
                    );
                } else {
                    $fileURL = sprintf("%s/assets/%s.css", $baseURL, $filename,);
                }

                switch (true) {
                    default:
                        return "<script async src=\"{$fileURL}\"></script>";

                }
        }

        throw new FileNotFoundException("Asset-File Not Found: {$assetFile->path()->filepath}", 404);
    }

    private function httpIsSecure(): bool
    {
        if (isset($_SERVER['X_FORWARDED_PROTO']) && strtolower($_SERVER['X_FORWARDED_PROTO']) === 'https') {
            return true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        $secureState = $_SERVER['HTTPS'] ?? null;
        return $secureState !== 'off' && !empty($secureState);
    }

    private function httpGetDomain(): ?string
    {
        foreach (['SERVER_NAME', 'HTTP_X_ORIGINAL_HOST', 'HTTP_HOST'] as $key) {
            if (isset($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }

        return null;
    }

    private function httpsGetBaseURL(): string
    {
        $scheme = $this->httpIsSecure() ? 'https' : 'http';
        $domain = $this->httpGetDomain();
        $path = dirname($_SERVER['SCRIPT_NAME']);

        return "{$scheme}://{$domain}{$path}";

    }
}
