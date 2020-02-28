<?php


namespace ricwein\Templater;


abstract class RecursiveProcessor extends Processor
{
    const MAX_DEPTH = 64;
    protected bool $matchedAction = false;

    public function hasMatched(): bool
    {
        try {
            return $this->matchedAction;
        } finally {
            $this->matchedAction = false;
        }
    }

}
