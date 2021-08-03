<?php

namespace Tests;

use App\Methods\PapiMethods;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;

class PapiMethodsIOTest extends TestCase
{
    private string $papi_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->papi_dir = getcwd();
    }

    public function testReadSpecFileJson()
    {
        $spec_file = $this->papi_dir.'/examples/reference/PetStore/PetStore.2021-07-23.json';
        $spec_array = PapiMethods::readSpecFile($spec_file);

        $this->assertEquals($spec_array['info']['version'], '2021-07-23');
    }

    public function testReadSpecFileYaml()
    {
        $spec_file = $this->papi_dir.'/examples/reference/PetStore/PetStore.2021-07-23.yml';
        $spec_array = PapiMethods::readSpecFile($spec_file);

        $this->assertEquals($spec_array['info']['version'], '2021-07-23');
    }

    public function testWriteJsonSpecFile()
    {
        $structure = [
            'reference' => []
        ];
        $root = vfsStream::setup('root', null, $structure);

        $spec_array = [
            'info' => [
                'title' => 'Simple Spec'
            ]
        ];
        $spec_path = $root->url().'/reference/test.json';

        PapiMethods::writeSpecFile(
            $spec_array,
            $spec_path,
            'json'
        );
        $this->assertTrue($root->hasChild('reference/test.json'));

        $spec_read = PapiMethods::readSpecFile($spec_path);
        $this->assertEquals($spec_read['info']['title'], 'Simple Spec');
    }

    public function testWriteYamlSpecFile()
    {
        $structure = [
            'reference' => []
        ];
        $root = vfsStream::setup('root', null, $structure);

        $spec_array = [
            'info' => [
                'title' => 'Simple Spec'
            ]
        ];
        $spec_path = $root->url().'/reference/test.yml';

        PapiMethods::writeSpecFile(
            $spec_array,
            $spec_path,
            'yaml'
        );
        $this->assertTrue($root->hasChild('reference/test.yml'));

        $spec_read = PapiMethods::readSpecFile($spec_path);
        $this->assertEquals($spec_read['info']['title'], 'Simple Spec');
    }

    public function testWriteTextToFile()
    {
        $structure = [
            'reference' => []
        ];
        $root = vfsStream::setup('root', null, $structure);

        $text_file_path = $root->url().'/test.txt';

        PapiMethods::writeTextToFile('random', $text_file_path);
        $this->assertTrue($root->hasChild('test.txt'));

        $text_read = file_get_contents($text_file_path, true);
        $this->assertEquals($text_read, 'random');
    }
}
