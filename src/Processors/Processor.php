<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Templater;
use ricwein\Tokenizer\Result\TokenStream;

/**
 * provide base worker
 */
abstract class Processor
{
    // for recursive processors
    protected const MAX_DEPTH = 64;

    protected Templater $templater;

    public function __construct(Templater $templater)
    {
        $this->templater = $templater;
    }


    abstract protected function startKeyword(): string;

    protected function endKeyword(): ?string
    {
        return null;
    }

    protected function forkKeywords(): ?array
    {
        return null;
    }

    public function isQualified(Statement $statement): bool
    {
        return $statement->beginsWith([$this->startKeyword()]);
    }

    public function isQualifiedEnd(Statement $statement): bool
    {
        if (null !== $endKeyword = $this->endKeyword()) {
            return $statement->beginsWith([$endKeyword]);
        }
        return false;
    }

    public function isQualifiedFork(Statement $statement): bool
    {
        if (null !== $forkKeywords = $this->forkKeywords()) {
            return $statement->beginsWith($forkKeywords);
        }
        return false;
    }

    /**
     * @inheritDoc
     * @throws RenderingException
     */
    abstract public function process(Statement $statement, TokenStream $stream): string;
}
