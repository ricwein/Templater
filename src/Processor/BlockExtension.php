<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater\Processor;

use ricwein\FileSystem\File;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\Resolver;
use ricwein\Templater\Engine\Worker;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Templater;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;

/**
 * simple Template parser with Twig-like syntax
 */
class BlockExtension extends Worker
{
    const ORIGIN = 'origin';
    const NAME = 'name';
    const CONTENT = 'content';
    const CONTAINS_NESTED_LEVEL = 'nesting';
    const OFFSET = 'offset';

    private Config $config;
    private Directory $basedir;

    public function __construct(Config $config, Directory $templateDir)
    {
        $this->config = $config;
        $this->basedir = $templateDir;
    }

    /**
     * @param string $content
     * @param array $bindings
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function replace(string $content, array $bindings = []): string
    {
        return $this->extendsBlocks($this->basedir, $content, $bindings, 0, []);
    }

    /**
     * @param Directory $basedir
     * @param string $content
     * @param array $bindings
     * @param int $currentDepth
     * @param array $openBlocks
     * @return string
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws Exception
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws UnexpectedValueException
     * @throws RuntimeException
     */
    private function extendsBlocks(Directory $basedir, string $content, array $bindings, int $currentDepth, array $openBlocks): string
    {
        // match for all {% extends ... %} blocks
        if (false === preg_match_all('/{%\s*extends\s*(.+)\s*%}/', $content, $extendsMatches)) {
            return $content;
        } elseif (count($extendsMatches) !== 2) {
            return $content;
        } elseif (empty($extendsMatches[0])) {
            return $content;
        }

        // look for {% block ... %} statements in the extended base template
        foreach ($extendsMatches[1] as $extendsTemplateFile) {

            $baseTemplateName = (new Resolver($bindings))->resolve(trim($extendsTemplateFile));
            $baseTemplateFile = Templater::getTemplateFile($basedir, $baseTemplateName, $this->config->fileExtension);
            if ($baseTemplateFile === null) {
                throw new FileNotFoundException("BaseTemplate '{$baseTemplateName}' not found", 404);
            }

            $baseTemplate = $baseTemplateFile->read();
            $baseBlocks = $this->getBlocks($baseTemplate);
            $currentBlocks = array_merge($this->getBlocks($content), $openBlocks);

            usort($currentBlocks, function (array $lhs, array $rhs): int {
                return $rhs[static::CONTAINS_NESTED_LEVEL] - $lhs[static::CONTAINS_NESTED_LEVEL];
            });
            $currentBlocks = array_values($currentBlocks);

            // actually replace content with our new template
            $content = $baseTemplate;

            // handle block extensions/replaces
            foreach ($baseBlocks as $baseBlock) {

                if (false === $matchKey = array_search($baseBlock[static::NAME], array_column($currentBlocks, static::NAME))) {
                    continue;
                }
                $matchingBlock = $currentBlocks[$matchKey];

                $blockContent = $matchingBlock[static::ORIGIN];
                $blockContent = preg_replace('/{{\s*parent\s*\(\)\s*}}/', $baseBlock[static::CONTENT], $blockContent);
                $blockContent = preg_replace_callback('/{{\s*block\s*\((.+)\)\s*}}/', function (array $match) use ($currentBlocks): string {
                    $blockName = trim($match[1], '\'"');
                    $matchingBlocks = array_filter($currentBlocks, function (array $block) use ($blockName): bool {
                        return $block[static::NAME] === $blockName;
                    });

                    if (count($matchingBlocks) < 1) {
                        return $match[0];
                    }
                    $matchingBlock = $matchingBlocks[array_key_first($matchingBlocks)];
                    return $matchingBlock[static::CONTENT];

                }, $blockContent);

                // remove already matched block from remaining blocks
                unset($currentBlocks[$matchKey]);

                // fixes array-keys by reindexing after removing an element
                $currentBlocks = array_values($currentBlocks);

                $content = str_replace($baseBlock[static::ORIGIN], $blockContent, $content);
            }

            $openBlocks = $currentBlocks;

            // handle recursive extensions
            if ($currentDepth <= (self::MAX_DEPTH - 2)) {
                $content = $this->extendsBlocks($basedir, $content, $bindings, $currentDepth + 1, $openBlocks);
            }
        }

        // remove remaining blocks
        $remainingBlocks = $this->getBlocks($content);
        foreach ($remainingBlocks as $block) {
            $content = str_replace($block[static::ORIGIN], $block[static::CONTENT], $content);
        }

        return $content;
    }

    /**
     * @param string $content
     * @return array
     * @throws UnexpectedValueException
     */
    private function getBlockMatches(string $content): array
    {
        $openBlockMatches = [];
        $closeBlockMatches = [];
        if (
            false === preg_match_all('/{%\s*block\s+(.+)\s*%}/U', $content, $openBlockMatches, PREG_OFFSET_CAPTURE)
            ||
            false === preg_match_all('/{%\s*endblock\s*%}/U', $content, $closeBlockMatches, PREG_OFFSET_CAPTURE)
        ) {
            return [];
        } elseif (count($openBlockMatches) !== 2 || count($closeBlockMatches) !== 1) {
            return [];
        } elseif (count($openBlockMatches[0]) !== count($closeBlockMatches[0])) {
            throw new UnexpectedValueException("unmatching 'block' and 'endblock' counts");
        }

        // merge open and close block into a single list for better sorting
        $blockList = [];
        foreach (array_keys($openBlockMatches[0]) as $key) {
            $blockList[] = [
                'type' => 'open',
                'nesting' => 0,
                'offset' => $openBlockMatches[0][$key][1],
                'name' => trim($openBlockMatches[1][$key][0]),
                'content' => $openBlockMatches[0][$key][0]
            ];
            $blockList[] = [
                'type' => 'close',
                'offset' => $closeBlockMatches[0][$key][1],
                'content' => $closeBlockMatches[0][$key][0]
            ];
        }

        // sort open and close blocks by offset to work out the order of nested blocks
        usort($blockList, function (array $lhs, array $rhs): int {
            return $lhs['offset'] - $rhs['offset'];
        });

        return $blockList;
    }

    /**
     * @param array $blockList
     * @param string $content
     * @return array
     * @throws UnexpectedValueException
     */
    private function matchBlockPairs(array $blockList, string $content): array
    {
        $openBlocks = [];
        $blocks = [];
        foreach ($blockList as $key => $block) {

            if ($block['type'] === 'open') {

                // open a new block
                $openBlocks[] = $block;

            } elseif ($block['type'] === 'close') {

                // close-block found!
                $lastOpenBlock = array_pop($openBlocks);
                foreach (array_keys($openBlocks) as $openKey) {
                    $openBlocks[$openKey]['nesting'] += 1;
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

                $blockOriginStart = $lastOpenBlock['offset'];
                $blockContentStart = $blockOriginStart + strlen($lastOpenBlock['content']);
                $blockContentEnd = $block['offset'];
                $blockOriginEnd = $blockContentEnd + strlen($block['content']);
                $blockOriginLength = $blockOriginEnd - $blockOriginStart;
                $blockContentLength = $blockContentEnd - $blockContentStart;

                $blockContent = substr($content, $blockContentStart, $blockContentLength);
                $blockOrigin = substr($content, $blockOriginStart, $blockOriginLength);

                $blocks[] = [
                    static::ORIGIN => $blockOrigin,
                    static::CONTENT => $blockContent,
                    static::NAME => $lastOpenBlock['name'],
                    static::CONTAINS_NESTED_LEVEL => $lastOpenBlock['nesting'],
                    static::OFFSET => $blockOriginStart,
                ];

            } else {
                throw new UnexpectedValueException("unable to find 'block'-start for endblock");
            }
        }

        // sort by contains-nested attribute of each block
        // if a block contains other nested block, it must be handled first
        usort($blocks, function (array $lhs, array $rhs): int {
            return $rhs[static::CONTAINS_NESTED_LEVEL] - $lhs[static::CONTAINS_NESTED_LEVEL];
        });

        return $blocks;
    }

    /**
     * @param string $content
     * @return array
     * @throws UnexpectedValueException
     */
    private function getBlocks(string $content): array
    {
        $blockList = $this->getBlockMatches($content);
        if (empty($blockList)) {
            return [];
        }

        return $this->matchBlockPairs($blockList, $content);
    }

}
