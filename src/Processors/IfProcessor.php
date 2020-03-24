<?php


namespace ricwein\Templater\Processors;


use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;

class IfProcessor extends Processor
{
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

        while ($token = $stream->next()) {

            if ($token instanceof Token) {
                $current['blocks'][] = (string)$token;
            } elseif ($token instanceof BlockToken) {

                $blockStatement = new Statement($token, $statement->context);

                // branch fork
                if ($this->isQualifiedFork($blockStatement)) {

                    $branches[] = $current;
                    $current = [
                        'type' => 'elseif',
                        'condition' => $blockStatement->remainingTokens(),
                        'blocks' => [],
                    ];
                }

                if ($blockStatement->beginsWith(['else'])) {

                    $branches[] = $current;
                    $current = [
                        'type' => 'else',
                        'blocks' => [],
                    ];
                }

                if ($this->isQualifiedEnd($blockStatement)) {

                    $branches[] = $current;

                    foreach ($branches as $branch) {
                        switch ($branch['type']) {

                            case 'if':
                            case 'elseif':
                                $conditionString = implode(' ', array_map(function (Token $token): string {
                                    return $token->token();
                                }, $branch['condition']));

                                if ($statement->context->resolver()->resolve($conditionString)) {
                                    return implode('', $branch['blocks']);
                                }
                                break;

                            case 'else':
                                return implode('', $branch['blocks']);
                        }
                    }

                    return '';
                }

                $current['blocks'] = array_merge($current['blocks'], $this->templater->resolveToken($token, $statement->context, $stream));
            }
        }

        throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
    }

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
}
