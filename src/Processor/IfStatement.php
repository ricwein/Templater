<?php


namespace ricwein\Templater\Processor;

use Exception;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Engine\Worker;

class IfStatement extends Worker
{
    const BRANCHES = 'branches';
    const ORIGIN = 'origin';
    const CONTENT = 'content';
    const OFFSET = 'offset';
    const CONDITION = 'condition';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $content
     * @param array $bindings
     * @return string
     * @throws Exception
     */
    public function replace(string $content, array $bindings = []): string
    {
        $resolver = new Resolver($bindings);
        while ($stmt = $this->getNextValidIfStatements($content)) {

            foreach ($stmt[static::BRANCHES] as $branch) {

                if (isset($branch[static::CONDITION])) {

                    try {
                        // check if the required condition is satisfied
                        if ($resolver->resolveCondition($branch[static::CONDITION])) {
                            $content = str_replace($stmt[static::ORIGIN], $branch[static::CONTENT], $content);
                            continue 2;
                        }

                    } catch (Exception $exception) {
                        if ($this->config->debug) {
                            throw $exception;
                        }
                    }

                } else {

                    // we found an else-branch which we can use
                    $content = str_replace($stmt[static::ORIGIN], $branch[static::CONTENT], $content);
                    continue 2;

                }
            }

            // nothing matched and no else-branch found, so we remove the whole statement
            $content = str_replace($stmt[static::ORIGIN], '', $content);
        }

        return $content;
    }

    /**
     * @param string $content
     * @return array|null
     * @throws UnexpectedValueException
     */
    private function getNextValidIfStatements(string $content): ?array
    {
        $statementList = $this->getIfStatementMatches($content);
        if ($statementList === null) {
            return null;
        }

        $statements = $this->matchStatementPairs($statementList, $content);
        return reset($statements);
    }

    /**
     * @param string $content
     * @return array|null
     * @throws UnexpectedValueException
     */
    private function getIfStatementMatches(string $content): ?array
    {
        $ifMatches = [];
        $elseIfMatches = [];
        $elseMatches = [];
        $endIfMatches = [];

        if (
            false === preg_match_all('/{%\s*if\s+(.+)\s*%}/U', $content, $ifMatches, PREG_OFFSET_CAPTURE)
            ||
            false === preg_match_all('/{%\s*endif\s*%}/U', $content, $endIfMatches, PREG_OFFSET_CAPTURE)
        ) {
            return null;
        } elseif (count($ifMatches) !== 2 || count($endIfMatches) !== 1) {
            return null;
        } elseif (count($ifMatches[0]) !== count($endIfMatches[0])) {
            throw new UnexpectedValueException("unmatching 'if' and 'endif' counts");
        }


        preg_match_all('/{%\s*else\s*%}/U', $content, $elseMatches, PREG_OFFSET_CAPTURE);
        if (count($elseMatches) > count($ifMatches)) {
            throw new UnexpectedValueException(sprintf("found more 'else' branches (%d) than 'if' statements (%d).", count($elseMatches), count($ifMatches)));
        }

        preg_match_all('/{%\s*else\s?if\s+(.+)\s*%}/U', $content, $elseIfMatches, PREG_OFFSET_CAPTURE);
        if (count($elseIfMatches) > count($ifMatches)) {
            throw new UnexpectedValueException(sprintf("found more 'else if'/'elseif' branches (%d) than 'if' statements (%d).", count($elseIfMatches), count($ifMatches)));
        }

        // merge all if statements into a single list for better sorting
        $statementList = [];
        foreach (array_keys($ifMatches[0]) as $key) {
            $statementList[] = [
                'type' => 'if',
                'offset' => $ifMatches[0][$key][1],
                'condition' => trim($ifMatches[1][$key][0]),
                'content' => $ifMatches[0][$key][0],
            ];
        }
        foreach (array_keys($elseIfMatches[0]) as $key) {
            $statementList[] = [
                'type' => 'elseif',
                'offset' => $elseIfMatches[0][$key][1],
                'content' => $elseIfMatches[0][$key][0],
                'condition' => trim($elseIfMatches[1][$key][0]),
            ];
        }
        foreach (array_keys($elseMatches[0]) as $key) {
            $statementList[] = [
                'type' => 'else',
                'offset' => $elseMatches[0][$key][1],
                'content' => $elseMatches[0][$key][0],
            ];
        }
        foreach (array_keys($endIfMatches[0]) as $key) {
            $statementList[] = [
                'type' => 'endif',
                'offset' => $endIfMatches[0][$key][1],
                'content' => $endIfMatches[0][$key][0],
            ];
        }

        if (empty($statementList)) {
            return null;
        }

        // sort open and close blocks by offset to work out the order of nested blocks
        usort($statementList, function (array $lhs, array $rhs): int {
            return $lhs['offset'] - $rhs['offset'];
        });

        return $statementList;
    }

    /**
     * @param array $statementList
     * @param string $content
     * @return array
     * @throws UnexpectedValueException
     */
    private function matchStatementPairs(array $statementList, string $content): array
    {

        $openStatements = [];
        $statements = [];
        foreach ($statementList as $statement) {

            if ($statement['type'] === 'if') {

                // open a new statement
                $openStatements[] = [
                    'branches' => [$statement],
                ];

            } elseif ($statement['type'] === 'elseif' || $statement['type'] === 'else') {

                // add statement branches
                $openStatements[array_key_last($openStatements)]['branches'][] = $statement;

            } elseif ($statement['type'] === 'endif') {

                // end of current statement found!
                /** @var array $lastOpenStatement */
                $lastOpenStatement = array_pop($openStatements);

                $lastOpenStatement['branches'][] = $statement;

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

                $lastStmt = null;

                $firstStmt = $lastOpenStatement['branches'][array_key_first($lastOpenStatement['branches'])];
                $lastStmt = $lastOpenStatement['branches'][array_key_last($lastOpenStatement['branches'])];
                $originStart = $firstStmt['offset'];
                $contentEnd = $lastStmt['offset'];
                $originEnd = $contentEnd + strlen($lastStmt['content']);
                $originEnd = $originEnd - $originStart;
                $loopOrigin = substr($content, $originStart, $originEnd);


                $statementBranches = [
                    static::OFFSET => $lastOpenStatement['branches'][array_key_first($lastOpenStatement['branches'])]['offset'],
                    static::ORIGIN => $loopOrigin,
                    static::BRANCHES => [],
                ];
                foreach (array_keys($lastOpenStatement['branches']) as $key) {
                    if (!isset($lastOpenStatement['branches'][$key + 1])) {
                        break;
                    }

                    $startStmt = $lastOpenStatement['branches'][$key];
                    $endStmt = $lastOpenStatement['branches'][$key + 1];

                    $originStart = $startStmt['offset'];
                    $contentStart = $originStart + strlen($startStmt['content']);
                    $contentEnd = $endStmt['offset'];
                    $contentLength = $contentEnd - $contentStart;

                    $loopContent = substr($content, $contentStart, $contentLength);

                    $stmt = [
                        static::CONTENT => $loopContent,
                    ];
                    if (isset($startStmt['condition'])) {
                        $stmt[static::CONDITION] = $startStmt['condition'];
                    }
                    $statementBranches[static::BRANCHES][] = $stmt;
                }

                $statements[] = $statementBranches;

            } else {
                throw new UnexpectedValueException("Unable to find 'endif' for 'if' statement.");
            }
        }

        usort($statements, function (array $lhs, array $rhs): int {
            return $lhs[static::OFFSET] - $rhs[static::OFFSET];
        });

        return $statements;
    }
}
