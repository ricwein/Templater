<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\FileSystem\File;
use ricwein\Templater\Engine\Resolver;
use ricwein\FileSystem\Storage;

class ResolverTest extends TestCase
{
    public function testDirectResolving()
    {
        $tests = [
            '"test"' => 'test',
            "'test'" => 'test',
            "true" => true,
            "false" => false,
            "42" => 42,
            "3.14" => 3.14,
            "null" => null,

            "['test']" => ['test'],
            "{'key': 'value'}" => ['key' => 'value'],

            "[{'key_test': 'nice value'}, 'yay']" => [['key_test' => 'nice value'], 'yay'],
            "{'key_test': ['value1', 'value2']}" => ['key_test' => ['value1', 'value2']],

            "[['value1', 'value2'], ['value3', 'value4']]" => [['value1', 'value2'], ['value3', 'value4']],
            "{'object1': {'key1': 'value1'}, 'object2' : {'key2': 'value2'}}" => ['object1' => ['key1' => 'value1'], 'object2' => ['key2' => 'value2']],

            "['value1', 'value2'].first()" => 'value1',
            "['value1', 'value2'].0" => 'value1',
            "['value1', 'value2'].last()" => 'value2',
            "['value1', 'value2'].1" => 'value2',
            "['value1', 'value2'].count()" => 2,
            "['value1', 'value2'].first().key()" => 0,
            "['value1', 'value2'].last().key()" => 1,
            "['value1', 'value2'].flip().first()" => 0,
            "['value1', 'value2'].flip().first().key()" => 'value1',
            "['value1', 'value2'].flip().value1" => 0,
            "['value1', 'value2'].flip().value2" => 1,
        ];

        $resolver = new Resolver();

        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    public function testUnmatchingBindings()
    {
        $resolver = new Resolver();

        $this->expectException(\ricwein\Templater\Exceptions\RuntimeException::class);
        $resolver->resolve("unknownvar");
    }

    public function testNestedUnmatchingBindings()
    {
        $resolver = new Resolver();

        $this->expectException(\ricwein\Templater\Exceptions\RuntimeException::class);
        $resolver->resolve("unknown.var");
    }

    public function testBindingsResolving()
    {
        $bindings = [
            'value1' => 'yay',
            'value2' => true,
            'nested' => ['test' => 'success'],
            'inline_array' => "['value1', 'value2']",
            'file' => new File(new Storage\Disk(__FILE__)),
        ];
        $tests = [
            'value1' => 'yay',
            'value2' => true,

            'nested.test' => 'success',
            'nested.first()' => 'success',
            'nested.first().key()' => 'test',

            'file.path().directory' => dirname(__FILE__),
            'file.path().extension' => 'php',

            /* @TODO: add iterative inline declaration resolution to Resolver::resolveVarPathToValue() */
            //'inline_array' => ['value1', 'value2'],
        ];

        $resolver = new Resolver($bindings);
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    public function testConditionResolving()
    {
        $tests = [
            "true ? 'yay'" => 'yay',
            "true ? 'yay' : 'oh noe'" => 'yay',

            "false ? 'yay'" => '',
            "false ? 'yay' : 'oh noe'" => 'oh noe',

            "true ? 'yay' : true ? 'oh no' : 'my bad'" => 'yay',
            "true ? 'yay' : false ? 'oh no' : 'my bad'" => 'yay',
            "false ? 'yay' : true ? 'oh no' : 'my bad'" => 'oh no',
            "false ? 'yay' : false ? 'oh no' : 'my bad'" => 'my bad',
        ];

        $resolver = new Resolver();
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    public function testConditionalBindingResolving()
    {
        $bindings = [
            'data' => [true, false],
            'strings' => ['yay', 'no', 'another string'],
        ];
        $tests = [
            "data.first() ? 'yay'" => 'yay',
            "data.last() ? 'yay'" => '',

            "'yay' in strings ? 'exists'" => 'exists',
            "'nother' in strings.last() ? 'also exists'" => 'also exists',

            "strings.first() == strings.0 ? 'success'" => 'success',
            "strings.first() != strings.last() ? 'mismatches'" => 'mismatches'
        ];

        $resolver = new Resolver($bindings);
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }
}
