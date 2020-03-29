<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Processors\Symbols\BaseSymbols;
use ricwein\Templater\Processors\Symbols\BlockSymbols;
use ricwein\Templater\Processors\Symbols\BranchSymbols;
use ricwein\Templater\Processors\Symbols\HeadOnlySymbols;
use ricwein\Templater\Templater;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\TokenStream;

/**
 * provide base processor
 */
abstract class Processor
{
    protected Templater $templater;

    /**
     * @var BaseSymbols|null
     */
    protected ?BaseSymbols $symbols = null;

    public function __construct(Templater $templater)
    {
        $this->templater = $templater;
    }

    abstract protected static function startKeyword(): string;

    protected static function endKeyword(): ?string
    {
        return null;
    }

    protected static function forkKeywords(): ?array
    {
        return null;
    }

    public static function isQualified(Statement $statement): bool
    {
        return $statement->beginsWith([static::startKeyword()]);
    }

    public static function isQualifiedEnd(Statement $statement): bool
    {
        if (null !== $endKeyword = static::endKeyword()) {
            return $statement->beginsWith([$endKeyword]);
        }
        return false;
    }

    public static function isQualifiedFork(Statement $statement): bool
    {
        if (null !== $forkKeywords = static::forkKeywords()) {
            return $statement->beginsWith($forkKeywords);
        }
        return false;
    }

    protected function requiresEnd(Statement $statement): bool
    {
        if (static::endKeyword() === null) {
            return false;
        }

        return true;
    }

    /**
     * @param Statement $statement
     * @param TokenStream $stream
     * @return self
     * @throws RuntimeException
     * @throws RenderingException
     */
    public function parse(Statement $statement, TokenStream $stream): self
    {
        $headTokens = $statement->remainingTokens();
        if (!$this->requiresEnd($statement)) {
            $this->symbols = new HeadOnlySymbols(static::startKeyword(), $headTokens);
            return $this;
        }


        /** @var array|null $branches */
        $branches = null;
        $isClosed = false;
        $currentBranch = new BlockSymbols(static::startKeyword(), $headTokens);

        // search endfor statement and save loop-content tokens for later processing
        while ($token = $stream->next()) {
            if ($token instanceof BlockToken && $token->block()->is('{%', '%}')) {

                $statement = new Statement($token, $statement->context);
                if (static::isQualifiedEnd($statement)) {

                    // ends current processor
                    if ($branches === null) {
                        $this->symbols = $currentBranch;
                        return $this;
                    }

                    $branches[] = $currentBranch;
                    $this->symbols = new BranchSymbols($branches);
                    return $this;

                } elseif (static::isQualifiedFork($statement)) {

                    // fork of current processor
                    if ($branches === null) {
                        $branches = [$currentBranch];
                    } else {
                        $branches[] = $currentBranch;
                    }

                    $currentBranch = new BlockSymbols(
                        $statement->matchedKeyword(),
                        $statement->remainingTokens()
                    );

                } else {

                    // starts new nested processor
                    $currentBranch->content[] = $this->templater->resolveProcessorToken($statement, $stream);

                }

            } else {
                $currentBranch->content[] = $token;
            }
        }

        if (!$isClosed) {
            throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
        }

    }

    /**
     * @inheritDoc
     * @return string[]
     */
    abstract public function process(Context $context): array;
}
