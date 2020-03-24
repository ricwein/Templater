<?php


namespace ricwein\Templater\Processors;


use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;

class BlockProcessor extends Processor
{
    /**
     * @param Statement $statement
     * @param TokenStream $stream
     * @return string
     * @throws RuntimeException
     * @throws RenderingException
     */
    public function process(Statement $statement, TokenStream $stream): string
    {
        $blocks = [];
        while ($token = $stream->next()) {

            if ($token instanceof Token) {
                $blocks[] = (string)$token;
            } elseif ($token instanceof BlockToken) {

                $blockStatement = new Statement($token, $statement->context);
                if ($blockStatement->beginsWith([$this->endKeyword()])) {

                    $blockContent = implode('', $blocks);

                    // TODO: keep block in context environment and insert if processing finished or something like this

                    return $blockContent;
                }

                $blocks = array_merge($blocks, $this->templater->resolveToken($token, $statement->context, $stream));
            }
        }

        throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
    }

    protected function startKeyword(): string
    {
        return 'block';
    }

    protected function endKeyword(): ?string
    {
        return 'endblock';
    }
}
