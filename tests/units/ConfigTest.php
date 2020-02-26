<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\Templater\Config;
use \ricwein\Templater\Exceptions\InvalidArgumentException;
use \ricwein\Templater\Exceptions\UnexpectedValueException;

class ConfigTest extends TestCase
{
    public function testConfigDefaults()
    {
        $config = new Config();

        $this->assertSame($config->debug, false);
        $this->assertSame($config->cacheDuration, 3600);
        $this->assertSame($config->cacheBusterEnabled, true);
        $this->assertSame($config->fileExtension, ".html.twig");
        $this->assertSame($config->stripComments, true);

        $this->assertSame($config->templateDir, null);
        $this->assertSame($config->assetDir, null);
        $this->assertSame($config->variables, []);
    }

    public function testConfigOverloading()
    {
        $config = new Config([
            'debug' => true,
            'cacheDuration' => 1,
            'cacheBusterEnabled' => false,
            'fileExtension' => '.test',
            'stripComments' => false,
            'templateDir' => __DIR__,
            'assetDir' => __DIR__,
            'variables' => ['test'],
        ]);

        $this->assertSame($config->debug, true);
        $this->assertSame($config->cacheDuration, 1);
        $this->assertSame($config->cacheBusterEnabled, false);
        $this->assertSame($config->fileExtension, ".test");
        $this->assertSame($config->stripComments, false);

        $this->assertSame($config->templateDir, __DIR__);
        $this->assertSame($config->assetDir, __DIR__);
        $this->assertSame($config->variables, ['test']);
    }

    public function testConfigOverloadingTypeSafety()
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Type Mismatch for Config property 'debug'. Expected type: boolean but got: string");
        $this->expectExceptionCode(500);

        new Config([
            'debug' => 'test',
        ]);
    }

    public function testConfigUnknownKeyAccess()
    {
        $config = new Config();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Templater Config property for key 'test' not found");
        $this->expectExceptionCode(500);

        $config->test;
    }

    public function testConfigUnknownKeyAccessOverloading()
    {
        $config = new Config(['test' => 'test']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Templater Config property for key 'test' not found");
        $this->expectExceptionCode(500);

        $config->test;
    }
}
