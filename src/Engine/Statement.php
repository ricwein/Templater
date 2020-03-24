<?php

namespace ricwein\Templater\Engine;

use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

class Statement
{
    private BlockToken $token;
    private ?TokenStream $stream = null;
    public Context $context;

    public function __construct(BlockToken $token, Context $context)
    {
        $this->token = $token;
        $this->context = $context;
    }

    public function content(): string
    {
        return $this->token->content();
    }

    public function contentTokenStream(): TokenStream
    {
        if ($this->stream !== null) {
            $this->stream->reset();
            return $this->stream;
        }

        $tokenizer = new Tokenizer([new Delimiter(' '), new Delimiter(PHP_EOL)], []);
        $this->stream = $tokenizer->tokenize($this->content());
        return $this->stream;
    }

    /**
     * @param string[]|string[][] $symbols
     * @return bool
     */
    public function beginsWith(array $symbols): bool
    {
        $stream = $this->contentTokenStream();
        $tokens = $stream->tokens();

        foreach ($symbols as $symbol) {

            if (is_string($symbol) && $tokens[0] instanceof Token && $tokens[0]->token() === $symbol) {
                $stream->reset(1);
                return true;
            }

            if (is_array($symbol)) {
                foreach (array_values($symbol) as $key => $symbolWord) {
                    if (!isset($tokens[$key]) || !$tokens[$key] instanceof Token || !$tokens[$key]->token() !== $symbolWord) {
                        continue 2;
                    }
                }

                $stream->reset(count($symbol));
                return true;
            }
        }

        return false;
    }

    /**
     * @return BaseToken[]
     */
    public function remainingTokens(): array
    {
        $tokens = [];
        $stream = $this->stream ?? $this->contentTokenStream();
        while ($token = $stream->next()) {
            $tokens[] = $token;
        }
        return $tokens;
    }
}
