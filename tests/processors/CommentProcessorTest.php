<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use ricwein\Templater\Processor;

class CommentProcessorTest extends TestCase
{
    static array $tests = [
        "This {# comment here #} is a test" => ["This  is a test", "This <!-- comment here --> is a test"],
        "This {# {{ test }} #} is a test" => ["This  is a test", "This <!-- {{ test }} --> is a test"],
        "This {# {% block 'test' %}{% endblock %} #} is a test" => ["This  is a test", "This <!-- {% block 'test' %}{% endblock %} --> is a test"],
        "This {# {# nasty nesting #} #} is a test" => ["This  is a test", "This <!-- {# nasty nesting #} --> is a test"],
    ];

    public function testCommentStripping()
    {
        $config = new Config(['stripComments' => true]);
        $processor = new Processor\Comments($config);

        foreach (static::$tests as $test => $expectations) {
            $result = $processor->replace($test);
            $this->assertSame($expectations[0], $result);
        }
    }

    public function testCommentConversion()
    {
        $config = new Config(['stripComments' => false]);
        $processor = new Processor\Comments($config);

        foreach (static::$tests as $test => $expectations) {
            $result = $processor->replace($test);
            $this->assertSame($expectations[1], $result);
        }
    }
}
