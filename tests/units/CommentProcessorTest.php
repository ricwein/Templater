<?php
declare(strict_types=1);

namespace ricwein\FileSystem\Tests\File;

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use ricwein\Templater\Processor;

class CommentProcessorTest extends TestCase
{
    public function testCommentStripping()
    {
        $test = "This {# comment here #} is a test";

        $config = new Config(['stripComments' => true]);
        $processor = new Processor\Comments($config);
        $result = $processor->replace($test);

        $this->assertSame("This  is a test", $result);
    }

    public function testCommentConversion()
    {
        $test = "This {# comment here #} is a test";

        $config = new Config(['stripComments' => false]);
        $processor = new Processor\Comments($config);
        $result = $processor->replace($test);

        $this->assertSame("This <!-- comment here --> is a test", $result);
    }
}
