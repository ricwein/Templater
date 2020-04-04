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
use ricwein\Templater\Resolver\TemplateResolver;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\TokenStream;

/**
 * provide base processor
 */
abstract class Processor
{
    protected TemplateResolver $templateResolver;

    /**
     * @var BaseSymbols|null
     */
    protected ?BaseSymbols $symbols = null;

    public function __construct(TemplateResolver $templateResolver)
    {
        $this->templateResolver = $templateResolver;
    }

    abstract public static function startKeyword(): string;

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
        return (static::endKeyword() !== null);
    }

    public function getSymbols(): BaseSymbols
    {
        return $this->symbols;
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
        $currentBranch = new BlockSymbols(static::startKeyword(), $headTokens);

        // search endfor statement and save loop-content tokens for later processing
        while ($token = $stream->next()) {
            if ($token instanceof BlockToken && $token->block()->is('{%', '%}')) {

                $localStatement = new Statement($token, $statement->context);
                if (static::isQualifiedEnd($localStatement)) {

                    // ends current processor
                    if ($branches === null) {
                        $this->symbols = $currentBranch;
                        return $this;
                    }

                    $branches[] = $currentBranch;
                    $this->symbols = new BranchSymbols($branches);
                    return $this;

                }

                if (static::isQualifiedFork($localStatement)) {

                    // fork of current processor
                    if ($branches === null) {
                        $branches = [$currentBranch];
                    } else {
                        $branches[] = $currentBranch;
                    }

                    $currentBranch = new BlockSymbols(
                        $localStatement->matchedKeyword(),
                        $localStatement->remainingTokens()
                    );

                } else {

                    // starts new nested processor
                    $currentBranch->content[] = $this->templateResolver->resolveProcessorToken($localStatement, $stream);

                }

            } else {
                $currentBranch->content[] = $token;
            }
        }

        throw new RuntimeException(sprintf('Unexpected end of template. Missing "%s" tag.', static::endKeyword()), 500);
    }

    /**
     * @inheritDoc
     * @return string[]
     */
    abstract public function process(Context $context): array;
}
