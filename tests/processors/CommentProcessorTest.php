<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use ricwein\Templater\Processors;

class CommentProcessorTest extends TestCase
{
    static array $tests = [
        "This {# comment here #} is a test" => ["This  is a test", "This <!-- comment here --> is a test"],
        "This {# {{ test }} #} is a test" => ["This  is a test", "This <!-- {{ test }} --> is a test"],
        "This {# {% block 'test' %}{% endblock %} #} is a test" => ["This  is a test", "This <!-- {% block 'test' %}{% endblock %} --> is a test"],
        "This {# test1 #} {# test2 #} is a test" => ["This   is a test", "This <!-- test1 --> <!-- test2 --> is a test"],
        "This {# test1 #} test {# test2 #} is a test" => ["This  test  is a test", "This <!-- test1 --> test <!-- test2 --> is a test"],
    ];

    public function testCommentStripping()
    {
        $config = new Config(['stripComments' => true]);

        foreach (static::$tests as $test => $expectations) {
            $result = (new Processors\Comments($test, $config))->process()->getResult();
            $this->assertSame($expectations[0], $result);
        }
    }

    public function testCommentConversion()
    {
        $config = new Config(['stripComments' => false]);

        foreach (static::$tests as $test => $expectations) {
            $result = (new Processors\Comments($test, $config))->process()->getResult();
            $this->assertSame($expectations[1], $result);
        }
    }
}
