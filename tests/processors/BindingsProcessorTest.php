<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use ricwein\Templater\Processors;

class BindingsProcessorTest extends TestCase
{
    public function testSimpleBindings()
    {
        return;
        $tests = [
            "Test1: {{ 'success' }} done" => "Test1: success done",
            "Test2: {{ true }}" => "Test2: 1"
        ];

        foreach ($tests as $input => $expectation) {
            $resolved = (new Processors\Bindings($input, new Config(['debug' => true])))->process()->getResult();
            $this->assertSame($expectation, $resolved);
        }
    }

}
