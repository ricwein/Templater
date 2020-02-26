<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Engine;

/**
 * provide base worker
 */
abstract class Worker
{
    /**
     * @var int
     */
    const MAX_DEPTH = 64;

    public abstract function replace(string $content): string;
}
