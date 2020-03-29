<?php

namespace ricwein\Templater\Processors;

use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\File;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Tokenizer\Result\BaseToken;

class CacheProcessor extends Processor
{
    protected static function startKeyword(): string
    {
        return 'cache';
    }

    protected static function endKeyword(): ?string
    {
        return 'endcache';
    }

    /**
     * @param BaseToken $token
     * @param Context $context
     * @return string
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     */
    private function getCacheKey(BaseToken $token, Context $context): string
    {
        $name = $context->resolver()->resolve($token->content());

        $key = sprintf(
            "view_%s|%d|%s.%d",
            str_replace(['/', '\\'], '.', ltrim($context->template()->path()->filepath, '/')),
            $context->template()->getTime(),
            is_scalar($name) ? $name : '',
            $token->line(),
        );

        return str_replace(
            ['{', '}', '(', ')', '/', '\\', '@', ':'],
            ['|', '|', '|', '|', '.', '.', '-', '_'],
            $key
        );
    }

    /**
     * @inheritDoc
     * @param Context $context
     * @return array
     * @throws FileSystemRuntimeException
     * @throws PhpfastcacheInvalidArgumentException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof BlockSymbols) {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $cache = $this->templater->getCache();

        if ($cache === null) {
            return $this->templater->resolveSymbols($this->symbols->content, $context);
        }

        $head = $this->symbols->headTokens();
        $nameToken = array_shift($head);

        $cacheKey = $this->getCacheKey($nameToken, $context);
        $cacheItem = $cache->getItem($cacheKey);

        // cache-hit!
        if (null !== $content = $cacheItem->get()) {
            return $content;
        }

        // parse cache-config from cache-statement
        $config = ['time' => 0];

        if (count($head) > 0) {
            $configString = implode('', $head);
            $parsedConfig = $context->resolver()->resolve($configString);
            if (is_numeric($parsedConfig)) {
                $config['time'] = (int)$parsedConfig;
            } elseif (is_array($parsedConfig)) {
                $config = array_replace_recursive($config, $parsedConfig);
            }
        }

        // parse cache-content
        $content = $this->templater->resolveSymbols($this->symbols->content, $context);

        // save cache
        $cacheItem->set($content);
        $cacheItem->expiresAfter($config['time']);
        $cache->save($cacheItem);

        return $content;
    }
}
