<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processors;

use ReflectionException;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;

/**
 * simple Template parser with Twig-like syntax
 */
class ForLoop extends Processor
{
    const ORIGIN = 'origin';
    const CONTENT = 'content';
    const CONTAINS_NESTED_LEVEL = 'nesting';
    const OFFSET = 'offset';
    const VARIABLE_FROM = 'var_from';
    const VARIABLE_AS = 'var_as';

    /**
     * @param array $bindings
     * @param BaseFunction[] $functions
     * @return ForLoop
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws ReflectionException
     */
    public function process(array $bindings = [], array $functions = []): self
    {
        while ($loop = static::getNextLoop($this->content)) {

            $binding = (new Resolver($bindings, $functions))->resolve($loop[static::VARIABLE_FROM]);
            if (!is_array($binding)) {
                throw new UnexpectedValueException("The for-loop variable '{$loop[static::VARIABLE_FROM]}' must be an array, but is not.");
            }

            // unroll loop by iterating through all branches
            $loopContent = [];
            foreach (array_keys($binding) as $key) {
                $replaces = [];

                // what do we want to pass into the loop? key and value or only value
                if (preg_match('/\((.+)\s*,\s*(.+)\)/', $loop[static::VARIABLE_AS], $matches) === 1) {
                    $replaces += [
                        $matches[1] => "{$loop[static::VARIABLE_FROM]}[{$key}].key()",
                        $matches[2] => "{$loop[static::VARIABLE_FROM]}[{$key}]",
                    ];
                } else {
                    $replaces += [
                        $loop[static::VARIABLE_AS] => "{$loop[static::VARIABLE_FROM]}[{$key}]",
                    ];
                }

                // replace unrolled variables in loop-bodies
                $loopContent[] = preg_replace_callback('/{[{%]\s*(.+)?\s*[}%]}/Us', function (array $match) use ($replaces): string {
                    $input = $match[0];

                    foreach ($replaces as $replace => $with) {

                        // only replace full word matches but not partial (.pages. with .page.1s.)
                        $input = preg_replace_callback("/(?:\.|\s+|{{|{%|\[|\(|){$replace}(?:\.|\s+|}}|%}|]\))/", function (array $replaceMatch) use ($replace, $with): string {
                            return str_replace($replace, $with, $replaceMatch[0]);
                        }, $input);
                    }

                    return $input;
                }, $loop[static::CONTENT]);
            }
            $unrolledLoop = implode(PHP_EOL, $loopContent);

            $this->content = str_replace($loop[static::ORIGIN], $unrolledLoop, $this->content);
        }

        return $this;
    }

    /**
     * @param string $content
     * @return array|null
     * @throws UnexpectedValueException
     */
    private static function getLoopMatches(string $content): ?array
    {
        $openLoopMatches = [];
        $closeLoopMatches = [];

        if (
            false === preg_match_all('/{%\s*for\s+(.+)\s+in\s+(.+)\s*%}/U', $content, $openLoopMatches, PREG_OFFSET_CAPTURE)
            ||
            false === preg_match_all('/{%\s*endfor\s*%}/U', $content, $closeLoopMatches, PREG_OFFSET_CAPTURE)
        ) {
            return null;
        } elseif (count($openLoopMatches) !== 3 || count($closeLoopMatches) !== 1) {
            return null;
        } elseif (count($openLoopMatches[0]) !== count($closeLoopMatches[0])) {
            throw new UnexpectedValueException("unmatching 'for' and 'endfor' counts");
        }

        // merge open and close loops into a single list for better sorting
        $loopList = [];
        foreach (array_keys($openLoopMatches[0]) as $key) {
            $loopList[] = [
                'type' => 'open',
                'nesting' => 0,
                'offset' => $openLoopMatches[0][$key][1],
                'variable_as' => trim($openLoopMatches[1][$key][0]),
                'variable_from' => trim($openLoopMatches[2][$key][0]),
                'content' => $openLoopMatches[0][$key][0]
            ];
            $loopList[] = [
                'type' => 'close',
                'offset' => $closeLoopMatches[0][$key][1],
                'content' => $closeLoopMatches[0][$key][0]
            ];
        }

        if (empty($loopList)) {
            return null;
        }

        // sort open and close blocks by offset to work out the order of nested blocks
        usort($loopList, function (array $lhs, array $rhs): int {
            return $lhs['offset'] - $rhs['offset'];
        });

        return $loopList;
    }

    /**
     * @param array $loopList
     * @param string $content
     * @return array
     * @throws UnexpectedValueException
     */
    private static function matchLoopPairs(array $loopList, string $content): array
    {
        $openLoops = [];
        $loops = [];
        foreach ($loopList as $loop) {

            if ($loop['type'] === 'open') {

                // open a new block
                $openLoops[] = $loop;

            } elseif ($loop['type'] === 'close') {

                // close-block found!
                $lastOpenLoop = array_pop($openLoops);
                foreach (array_keys($openLoops) as $openKey) {
                    $openLoops[$openKey]['nesting'] += 1;
                }

                /**
                 * @ATTENTION
                 * since preg_match_all with PREG_OFFSET_CAPTURE operates with
                 * NON MULTIEBYTE (non-unicode, non-utf8) string offsets, the following
                 * offset-based string operations MUST BE done with non multi-byte-safe
                 * implementation!
                 *
                 * @DO: substr(), strlen()
                 * @DONT: mb_substr(), mb_strlen()
                 */

                $loopOriginStart = $lastOpenLoop['offset'];
                $loopContentStart = $loopOriginStart + strlen($lastOpenLoop['content']);
                $loopContentEnd = $loop['offset'];
                $loopOriginEnd = $loopContentEnd + strlen($loop['content']);
                $loopOriginLength = $loopOriginEnd - $loopOriginStart;
                $loopContentLength = $loopContentEnd - $loopContentStart;

                $loopContent = substr($content, $loopContentStart, $loopContentLength);
                $loopOrigin = substr($content, $loopOriginStart, $loopOriginLength);


                $loops[] = [
                    static::ORIGIN => $loopOrigin,
                    static::CONTENT => $loopContent,
                    static::CONTAINS_NESTED_LEVEL => $lastOpenLoop['nesting'],
                    static::OFFSET => $loopOriginStart,
                    static::VARIABLE_FROM => $lastOpenLoop['variable_from'],
                    static::VARIABLE_AS => $lastOpenLoop['variable_as'],
                ];

            } else {
                throw new UnexpectedValueException("unable to find 'for'-start for endfor");
            }
        }

        // sort by contains-nested attribute of each block
        // if a block contains other nested block, it must be handled first
        usort($loops, function (array $lhs, array $rhs): int {
            return $rhs[static::CONTAINS_NESTED_LEVEL] - $lhs[static::CONTAINS_NESTED_LEVEL];
        });

        return $loops;
    }

    /**
     * @param string $content
     * @return array|null
     * @throws UnexpectedValueException
     */
    private static function getNextLoop(string $content): ?array
    {
        $loopList = static::getLoopMatches($content);
        if (empty($loopList)) {
            return null;
        }

        $loops = static::matchLoopPairs($loopList, $content);

        return reset($loops);
    }
}
