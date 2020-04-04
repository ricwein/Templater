<?php

namespace ricwein\Templater\Processors;

use Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Tokenizer\Result\BaseToken;

class CacheProcessor extends Processor
{
    public static function startKeyword(): string
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
        $name = $context->expressionResolver()->resolve($token->content(), $token->line());

        $key = sprintf(
            "view_%s|%d|%s.%d|%d",
            str_replace(['/', '\\'], '.', ltrim($context->template()->path()->filepath, '/')),
            $context->template()->getTime(),
            is_scalar($name) ? $name : '',
            $token->line(),
            $this->templater->getConfig()->debug ? 1 : 0,
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

        $config = ['time' => $this->templater->getConfig()->cacheDuration];

        // parse cache-config from statement if available
        if (count($head) > 0) {
            $configString = implode('', $head);
            $parsedConfig = $context->expressionResolver()->resolve($configString, $nameToken->line());
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
