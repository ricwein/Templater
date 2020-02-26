<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use ricwein\Templater\Processor;

class BindingsProcessorTest extends TestCase
{
    public function testSimpleBindings()
    {
        $tests = [
            "Test1: {{ 'success' }} done" => "Test1: success done",
            "Test2: {{ true }}" => "Test2: 1"
        ];
        $processor = new Processor\Bindings(new Config(['debug' => true]));

        foreach ($tests as $input => $expectation) {
            $resolved = $processor->replace($input);
            $this->assertSame($expectation, $resolved);
        }
    }

}
