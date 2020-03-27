<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Resolver\Resolver;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

class ForLoopProcessor extends Processor
{

    protected function startKeyword(): string
    {
        return 'for';
    }

    protected function endKeyword(): ?string
    {
        return 'endfor';
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function process(Statement $statement, TokenStream $stream): string
    {
        /** @var BaseToken[] $loopContent */
        $loopContent = [];
        $isClosedLoop = false;
        $loopHeadString = implode('', $statement->remainingTokens());

        // search endfor statement and save loop-content tokens for later processing
        while ($token = $stream->next()) {

            if ($token instanceof Token) {

                $loopContent[] = $token;

            } elseif ($token instanceof BlockToken) {

                $blockStatement = new Statement($token, $statement->context);
                if ($token->block()->is('{%', '%}') && $this->isQualifiedEnd($blockStatement)) {
                    $isClosedLoop = true;
                    break;
                } else {
                    $loopContent[] = $token;
                }

            }
        }

        if (!$isClosedLoop) {
            throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
        }

        // process actual for-loop
        $loopIterations = [];
        [$loopKeyName, $loopValueName, $loopSource, $loopCondition] = $this->parseLoopHead($loopHeadString, $statement);

        $loopSource = (new Resolver($statement->context->bindings, $statement->context->functions))->resolve($loopSource);

        if (!is_array($loopSource) && !is_iterable($loopSource) && !is_countable($loopSource)) {
            throw new RuntimeException(sprintf('Unable to loop above non-countable object of type: %s', is_object($loopSource) ? sprintf('class(%s)', get_class($loopSource)) : gettype($loopSource)), 500);
        }

        $index = 0;
        $firstKey = array_key_first($loopSource);
        $lastKey = array_key_last($loopSource);
        $length = count($loopSource);

        $localStream = new TokenStream($loopContent);


        foreach ($loopSource as $key => $value) {

            $loopParameters = [$loopValueName => $value];
            if ($loopKeyName !== null) {
                $loopParameters[$loopKeyName] = $key;
            }

            $loopContext = new Context(
                $statement->context->template(),
                array_replace_recursive($statement->context->bindings, $loopParameters, ['loop' => [
                    'index0' => $index++,
                    'index' => $index,
                    'first' => $key === $firstKey,
                    'last' => $key === $lastKey,
                    'length' => $length,
                ]]),
                $statement->context->functions,
                $statement->context->environment
            );

            if ($loopCondition !== null) {
                $satisfied = (new Resolver($loopContext->bindings, $loopContext->functions))->resolve($loopCondition);
                if (!$satisfied) {
                    continue;
                }
            }

            $localStream->reset();
            $loopIteration = $this->templater->resolveStream($localStream, $loopContext);
            $loopIterations[] = implode('', $loopIteration) . PHP_EOL;
        }

        return PHP_EOL . implode('', $loopIterations);
    }

    /**
     * @param string $loopHeadString
     * @param Statement $statement
     * @return array
     * @throws RenderingException
     */
    private function parseLoopHead(string $loopHeadString, Statement $statement): array
    {
        $inDelimiter = new Delimiter(' in ');
        $ifDelimiter = new Delimiter(' if ');

        $headTokenStream = (new Tokenizer([$inDelimiter, $ifDelimiter, new Delimiter(',')], [
            new Block('(', ')', true),
            new Block('[', ']', false),
            new Block('{', '}', false),
            new Block('\'', '\'', false),
            new Block('"', '"', false),
        ]))->tokenize($loopHeadString);

        /** @var BaseToken[] $current */
        $current = [];
        /** @var string|null $loopKeyName */
        $loopKeyName = null;
        /** @var string|null $loopValueName */
        $loopValueName = null;
        /** @var string|null $loopSource */
        $loopSource = null;
        /** @var string|null $loopCondition */
        $loopCondition = null;

        while ($token = $headTokenStream->next()) {

            if ($token->delimiter() === $inDelimiter && $loopValueName === null) {
                if (count($current) === 1 && $current[0] instanceof Token) {
                    $loopValueName = trim($current[0]->token());

                    $current = [];
                } elseif (count($current) === 1 && $current[0] instanceof BlockToken && $current[0]->block()->is('(', ')') && count($current[0]->tokens()) === 2) {
                    /** @var BaseToken[] $keys */
                    $keys = $current[0]->tokens();

                    $loopKeyName = trim($keys[0]->content());
                    $loopValueName = trim($keys[1]->content());

                    $current = [];
                } elseif (count($current) === 2 && $current[0] instanceof Token && $current[1] instanceof Token) {
                    $loopKeyName = trim($current[0]->token());
                    $loopValueName = trim($current[1]->token());

                    $current = [];
                } else {
                    throw new RenderingException(
                        sprintf('Invalid key/key-value definition in "for-in" loop head. Got %d parameter(s): %s', count($current), implode('', array_map('trim', $current))),
                        500,
                        null,
                        $statement->context->template(),
                        $statement->line()
                    );
                }
            }

            if ($token->delimiter() === $ifDelimiter && $loopSource === null) {
                $loopSource = implode('', $current);
                $current = [];
            }

            $current[] = $token;
        }
        if ($loopSource === null) {
            $loopSource = implode('', $current);
        } else {
            $loopCondition = implode('', $current);
            if (strpos($loopCondition, $ifDelimiter->symbol()) === 0) {
                $loopCondition = trim(substr($loopCondition, $ifDelimiter->length()));
            }
        }

        if (strpos($loopSource, $inDelimiter->symbol()) === 0) {
            $loopSource = trim(substr($loopSource, $inDelimiter->length()));
        }

        return [
            $loopKeyName,
            $loopValueName,
            $loopSource,
            $loopCondition,
        ];
    }
}
