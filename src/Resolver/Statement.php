<?php

namespace ricwein\Templater\Resolver;

use ricwein\Templater\Engine\Context;
use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

class Statement extends Resolver
{
    private BlockToken $token;
    private ?TokenStream $stream = null;
    public Context $context;

    /**
     * @var BaseToken[]
     */
    private array $keywordTokens = [];
    private array $tailTokens = [];

    public function __construct(BlockToken $token, Context $context)
    {
        $this->token = $token;
        $this->context = $context;
        $this->tokenizer = new Tokenizer([new Delimiter(' '), new Delimiter(PHP_EOL)], []);
    }

    public function content(): string
    {
        return $this->token->content();
    }

    public function line(): int
    {
        return $this->token->line();
    }

    public function contentTokenStream(): TokenStream
    {
        if ($this->stream !== null) {
            $this->stream->reset();
            return $this->stream;
        }

        $this->stream = $this->tokenizer->tokenize($this->content(), $this->line());
        return $this->stream;
    }

    /**
     * @param string[] $symbols
     * @return bool
     */
    public function beginsWith(array $symbols): bool
    {
        $stream = $this->contentTokenStream();
        $tokens = $stream->tokens();

        foreach ($symbols as $symbol) {

            if (is_string($symbol) && $tokens[0] instanceof Token && $tokens[0]->token() === $symbol) {
                $this->keywordTokens = [$tokens[0]];
                $stream->reset(1);
                $this->tailTokens = $this->getRemainingTokens($stream);
                return true;
            }

            if (is_array($symbol)) {
                $this->keywordTokens = [];
                foreach (array_values($symbol) as $key => $symbolWord) {
                    if (!isset($tokens[$key]) || !$tokens[$key] instanceof Token || $tokens[$key]->token() !== $symbolWord) {
                        continue 2;
                    }
                    $this->keywordTokens[] = $tokens[$key];
                }

                $stream->reset(count($symbol));
                $this->tailTokens = $this->getRemainingTokens($stream);
                return true;
            }
        }

        return false;
    }

    private function getRemainingTokens(TokenStream $stream): array
    {
        $tokens = [];
        while ($token = $stream->next()) {
            $tokens[] = $token;
        }
        return $tokens;
    }

    /**
     * @return BaseToken[]
     */
    public function remainingTokens(): array
    {
        return $this->tailTokens;
    }

    /**
     * @return BaseToken[]
     */
    public function keywordTokens(): array
    {
        return $this->keywordTokens;
    }

    public function matchedKeyword(): string
    {
        return implode('', array_map(
            fn(BaseToken $token): string => trim($token->content()),
            $this->keywordTokens()
        ));
    }
}
