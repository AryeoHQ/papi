<?php

namespace Tests;

use App\Methods\PapiMethods;
use PHPUnit\Framework\TestCase;

class PapiMethodsTest extends TestCase
{
    public function testValidFilePath()
    {
        $papi_dir = getcwd();
        
        $this->assertTrue(PapiMethods::validFilePath($papi_dir.'/phpunit.xml'));
        $this->assertFalse(PapiMethods::validFilePath($papi_dir.'/app'));
        $this->assertFalse(PapiMethods::validFilePath($papi_dir.'/unknown'));
    }
}
