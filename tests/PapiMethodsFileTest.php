<?php

namespace Tests;

use App\Methods\PapiMethods;
use PHPUnit\Framework\TestCase;

class PapiMethodsFileTest extends TestCase
{
    private string $papi_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->papi_dir = getcwd();
    }

    public function testValidFilePath()
    {
        $this->assertTrue(PapiMethods::validFilePath($this->papi_dir.'/phpunit.xml'));
        $this->assertFalse(PapiMethods::validFilePath($this->papi_dir.'/app'));
        $this->assertFalse(PapiMethods::validFilePath($this->papi_dir.'/unknown'));
    }

    public function testSpecTestDirectory()
    {
        $project_dir = '/Users/jdoe/app';

        $this->assertEquals(
            PapiMethods::specTestDirectory($project_dir),
            '/Users/jdoe/app/tests/Spec'
        );
    }

    public function testScanDirRecursively()
    {
        $files = PapiMethods::scandirRecursively(getcwd().'/bin');

        $this->assertCount(1, $files);
        $this->assertEquals($files[0], 'papi');
    }

    public function testValidExtensions()
    {
        $this->assertEquals(
            PapiMethods::validExtensions('yaml'),
            ['yml', 'YML', 'yaml', 'YAML']
        );

        $this->assertEquals(
            PapiMethods::validExtensions('json'),
            ['json', 'JSON']
        );

        $this->assertEquals(
            PapiMethods::validExtensions('random'),
            []
        );
    }

    public function testValidExtension()
    {
        $this->assertTrue(PapiMethods::validExtension('yaml', 'YML'));
        $this->assertTrue(PapiMethods::validExtension('json', 'json'));
        $this->assertFalse(PapiMethods::validExtension('json', 'js'));
    }

    public function testSpecFilesInDir()
    {
        $examples_dir = $this->papi_dir.'/examples';

        $files = PapiMethods::specFilesInDir($examples_dir.'/reference/PetStore', 'json');
        $this->assertCount(2, $files);

        $files = PapiMethods::specFilesInDir($examples_dir.'/reference/PetStore', 'js');
        $this->assertCount(0, $files);
    }

    public function testSpecNameAndVersion()
    {
        $spec_file = $this->papi_dir.'/examples/reference/PetStore/PetStore.2021-07-23.json';
        
        $results = PapiMethods::specNameAndVersion($spec_file);

        $this->assertEquals($results[0], 'PetStore');
        $this->assertEquals($results[1], '2021-07-23');
    }
}
