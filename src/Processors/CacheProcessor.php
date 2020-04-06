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
     * @param BaseToken[] $tokens
     * @param Context $context
     * @return string
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     */
    private function getCacheKey(array $tokens, Context $context): string
    {
        $firstToken = reset($tokens);
        $name = $context->expressionResolver()->resolve(implode('', $tokens), $firstToken->line());

        $key = sprintf(
            'view_%s|%d|%s.%d|%d',
            ltrim($context->template()->path()->real, '/'),
            $context->template()->getTime(),
            is_scalar($name) ? $name : '',
            $firstToken->line(),
            $this->templateResolver->getConfig()->debug ? 1 : 0,
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
            throw new RuntimeException(sprintf('Unsupported Processor-Symbols of type: %s', substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $cache = $this->templateResolver->getCache();

        if ($cache === null) {
            return $this->templateResolver->resolveSymbols($this->symbols->content, $context);
        }

        $optionTokens = $this->symbols->headTokens();
        $nameTokens = [];
        while (true) {
            $token = $optionTokens[array_key_first($optionTokens)];
            if (is_numeric($token->content()) || strpos($token->content(), '{') === 0 || strpos($token->content(), '[') === 0) {
                break;
            }
            $nameTokens[] = array_shift($optionTokens);
        }

        $cacheKey = $this->getCacheKey($nameTokens, $context);
        $cacheItem = $cache->getItem($cacheKey);

        // cache-hit!
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $config = ['time' => $this->templateResolver->getConfig()->cacheDuration];

        // parse cache-config from statement if available
        if (count($optionTokens) > 0) {
            $parsedConfig = $context->expressionResolver()->resolve(
                implode('', $optionTokens),
                reset($optionTokens)->line()
            );

            if (is_numeric($parsedConfig)) {
                $config['time'] = (int)$parsedConfig;
            } elseif (is_array($parsedConfig)) {
                $config = array_replace_recursive($config, $parsedConfig);
            }
        }

        // parse cache-content
        $content = $this->templateResolver->resolveSymbols($this->symbols->content, $context);

        // save cache
        $cacheItem->set($content);
        $cacheItem->expiresAfter($config['time']);
        $cache->save($cacheItem);

        return $content;
    }
}
