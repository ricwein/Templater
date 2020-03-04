<?php

namespace units;

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Tokenizer\InputSymbols\Block;
use ricwein\Templater\Tokenizer\InputSymbols\Delimiter;
use ricwein\Templater\Tokenizer\Result\ResultBlock;
use ricwein\Templater\Tokenizer\Result\ResultSymbol;
use ricwein\Templater\Tokenizer\Tokenizer;

class TokenizerTest extends TestCase
{
    protected Tokenizer $tokenizer;

    private function debugPrintTokenized(string $input)
    {
        die(PHP_EOL . PHP_EOL . implode(PHP_EOL . PHP_EOL, $this->tokenizer->tokenize($input)) . PHP_EOL);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $delimiter = [new Delimiter('.'), new Delimiter('|')];
        $blocks = [
            new Block('[', ']', true),
            new Block('(', ')', true),
            new Block('{', '}', false),
            new Block('\'', null, false),
            new Block('"', null, false),
        ];

        $this->tokenizer = new Tokenizer($delimiter, $blocks);
    }

    public function testSimpleDelimiter()
    {
        $testString = 'test.123';
        $expected = [new ResultSymbol('test', null), new ResultSymbol('123', new Delimiter('.'))];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = 'test,123';
        $expected = [new ResultSymbol('test,123', null)];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = 'test.123 | lol';
        $expected = [new ResultSymbol('test', null), new ResultSymbol('123', new Delimiter('.')), new ResultSymbol('lol', new Delimiter('|'))];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = 'really.long.test.lol.last';
        $expected = [
            new ResultSymbol('really', null),
            new ResultSymbol('long', new Delimiter('.')),
            new ResultSymbol('test', new Delimiter('.')),
            new ResultSymbol('lol', new Delimiter('.')),
            new ResultSymbol('last', new Delimiter('.'))
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

    public function testSimpleBlocks()
    {
        $testString = '[test]';
        $expected = [
            (new ResultBlock(new Block('[', ']', true), null))
                ->withSymbols([new ResultSymbol('test', null)])
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = '[(test)]';
        $expected = [
            (new ResultBlock(new Block('[', ']', true), null))
                ->withSymbols([(new ResultBlock(new Block('(', ')', true), null))
                    ->withSymbols([new ResultSymbol('test', null)])])
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = '[("test")]';
        $expected = [
            (new ResultBlock(new Block('[', ']', true), null))
                ->withSymbols([(new ResultBlock(new Block('(', ')', true), null))
                    ->withSymbols([new ResultSymbol('"test"', null)])])
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

    public function testBlockSymbols()
    {
        $testString = '[test.123]';
        $expected = [
            (new ResultBlock(new Block('[', ']', true), null))
                ->withSymbols([new ResultSymbol('test', null), new ResultSymbol('123', new Delimiter('.'))])
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = '[(test).123]';
        $expected = [
            (new ResultBlock(new Block('[', ']', true), null))
                ->withSymbols([
                    (new ResultBlock(new Block('(', ')', true), null))->withSymbols([new ResultSymbol('test', null)]),
                    new ResultSymbol('123', new Delimiter('.'))
                ])
        ];

        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

    public function testBlockPrefix()
    {
        $testString = 'functionCall()';
        $expected = [
            (new ResultBlock(new Block('(', ')', true), null))->withPrefix('functionCall'),
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = 'really.functionCall().last';
        $expected = [
            new ResultSymbol('really', null),
            (new ResultBlock(new Block('(', ')', true), new Delimiter('.')))->withPrefix('functionCall'),
            new ResultSymbol('last', new Delimiter('.'))
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

    public function testBlockSuffix()
    {
        $testString = 'really.()test';
        $expected = [
            new ResultSymbol('really', null),
            (new ResultBlock(new Block('(', ')', true), new Delimiter('.')))->withSuffix('test'),
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = '()test';
        $expected = [
            (new ResultBlock(new Block('(', ')', true), null))->withSuffix('test'),
        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));

        $testString = 'really.()test.last';
        $expected = [
            new ResultSymbol('really', null),
            (new ResultBlock(new Block('(', ')', true), new Delimiter('.')))->withSuffix('test'),
            new ResultSymbol('last', new Delimiter('.')),

        ];
        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

    public function testNestedBlocks()
    {
        $testString = 'var.functionCall(test).last';

        $expected = [
            new ResultSymbol('var', null),
            (new ResultBlock(new Block('(', ')', true), new Delimiter('.')))->withPrefix('functionCall')->withSymbols([
                new ResultSymbol('test', null),
            ]),
            new ResultSymbol('last', new Delimiter('.'))
        ];

        $this->assertEquals($expected, $this->tokenizer->tokenize($testString));
    }

}
