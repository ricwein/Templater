<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Templater\Processors\Symbols\BranchSymbols;
use ricwein\Templater\Resolver\ExpressionResolver;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\InputSymbols\Delimiter;
use ricwein\Tokenizer\Result\BaseToken;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Tokenizer;

class ForLoopProcessor extends Processor
{
    public static function startKeyword(): string
    {
        return 'for';
    }

    protected static function forkKeywords(): ?array
    {
        return ['else'];
    }

    protected static function endKeyword(): ?string
    {
        return 'endfor';
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws RenderingException
     */
    public function process(Context $context): array
    {
        /** @var BlockSymbols $forBlock */
        /** @var BlockSymbols|null $elseBlock */
        if ($this->symbols instanceof BlockSymbols) {
            $forBlock = $this->symbols;
            $elseBlock = null;
        } elseif ($this->symbols instanceof BranchSymbols) {
            $forBlock = $this->symbols->branch(0);
            $elseBlock = $this->symbols->branch(1);
        } else {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        // pre-process loop conditions and resolve variables
        $loopIterations = [];
        $headTokens = $forBlock->headTokens();
        $loopHeadString = implode('', $headTokens);
        $line = $forBlock->headTokens()[0]->line();
        [$loopKeyName, $loopValueName, $loopSource, $loopCondition] = $this->parseLoopHead($loopHeadString, $context, $line);

        $line = 1;
        if (false !== $firstToken = reset($headTokens)) {
            $line = $firstToken->line();
        }
        $loopSource = $context->expressionResolver()->resolve($loopSource, $line);

        if (!is_array($loopSource) && !is_countable($loopSource) && !is_iterable($loopSource)) {
            throw new RuntimeException(sprintf('Unable to loop above non-countable object of type: %s', is_object($loopSource) ? sprintf('class(%s)', get_class($loopSource)) : gettype($loopSource)), 500);
        }

        $hasAtLeastOneIteration = false;
        $index = 0;

        $firstKey = null;
        $lastKey = null;
        $length = null;

        if (is_countable($loopSource)) {
            $firstKey = array_key_first($loopSource);
            $lastKey = array_key_last($loopSource);
            $length = count($loopSource);
        }

        foreach ($loopSource as $key => $value) {

            // build custom loop-context with loop-scope only variables
            $loopParameters = [$loopValueName => $value];
            if ($loopKeyName !== null) {
                $loopParameters[$loopKeyName] = $key;
            }

            $loopContext = new Context(
                $context->template(),
                array_replace_recursive($context->bindings, $loopParameters, ['loop' => [
                    'index0' => $index++,
                    'index' => $index,
                    'first' => $firstKey !== null ? ($key === $firstKey) : null,
                    'last' => $firstKey !== null ? ($key === $lastKey) : null,
                    'length' => $length,
                ]]),
                $context->functions,
                $context->environment
            );

            if ($loopCondition !== null) {
                $satisfied = (new ExpressionResolver($loopContext->bindings, $loopContext->functions))->resolve($loopCondition);
                if (!$satisfied) {
                    continue;
                }
            }

            $loopIteration = $this->templateResolver->resolveSymbols($forBlock->content, $loopContext);
            $loopIterations[] = implode('', $loopIteration);

            $hasAtLeastOneIteration = true;
        }

        if (!$hasAtLeastOneIteration && $elseBlock !== null) {
            return $this->templateResolver->resolveSymbols($elseBlock->content, $context);
        }

        return $loopIterations;
    }

    /**
     * @param string $loopHeadString
     * @param Context $context
     * @param int $line
     * @return array
     * @throws RenderingException
     */
    private function parseLoopHead(string $loopHeadString, Context $context, int $line): array
    {
        $inDelimiter = new Delimiter(' in ');
        $ifDelimiter = new Delimiter(' if ');

        $headTokenStream = (new Tokenizer([$inDelimiter, $ifDelimiter, new Delimiter(',')], [
            new Block('(', ')', true),
            new Block('[', ']', false),
            new Block('{', '}', false),
            new Block('\'', '\'', false),
            new Block('"', '"', false),
        ]))->tokenize($loopHeadString, $line);

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
                        $context->template(),
                        $line
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
