<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;

class IfProcessor extends Processor
{
    protected function startKeyword(): string
    {
        return 'if';
    }

    protected function endKeyword(): ?string
    {
        return 'endif';
    }

    protected function forkKeywords(): ?array
    {
        return ['elseif', ['else', 'if']];
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function process(Statement $statement, TokenStream $stream): string
    {
        $branches = [];
        $current = [
            'type' => 'if',
            'condition' => $statement->remainingTokens(),
            'blocks' => []
        ];
        $isClosed = false;

        // search endif statement and save branch-tokens for later processing
        while ($token = $stream->next()) {

            if ($token instanceof Token) {

                $current['blocks'][] = $token;

            } elseif ($token instanceof BlockToken) {

                $blockStatement = new Statement($token, $statement->context);

                if ($token->block()->is('{%', '%}') && $this->isQualifiedFork($blockStatement)) {

                    // branch elseif-fork
                    $branches[] = $current;
                    $current = [
                        'type' => 'elseif',
                        'condition' => $blockStatement->remainingTokens(),
                        'blocks' => [],
                    ];

                } else if ($token->block()->is('{%', '%}') && $blockStatement->beginsWith(['else'])) {

                    // branch else-fork
                    $branches[] = $current;
                    $current = [
                        'type' => 'else',
                        'blocks' => [],
                    ];


                } else if ($token->block()->is('{%', '%}') && $this->isQualifiedEnd($blockStatement)) {

                    $branches[] = $current;
                    $isClosed = true;
                    break;

                } else {
                    $current['blocks'][] = $token;
                }

            }
        }

        if (!$isClosed) {
            throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
        }

        // process actual if-statements
        foreach ($branches as $branch) {
            switch ($branch['type']) {

                case 'if':
                case 'elseif':
                    $conditionString = implode(' ', array_map(fn(Token $conditionToken): string => $conditionToken->token(), $branch['condition']));

                    if ($statement->context->resolver()->resolve($conditionString)) {
                        $localStream = new TokenStream($branch['blocks']);
                        $resolved = $this->templater->resolveStream($localStream, $statement->context);
                        return implode('', $resolved) . PHP_EOL;
                    }
                    break;

                case 'else':
                    $localStream = new TokenStream($branch['blocks']);
                    $resolved = $this->templater->resolveStream($localStream, $statement->context);
                    return implode('', $resolved) . PHP_EOL;
            }
        }

        return '';
    }

}
