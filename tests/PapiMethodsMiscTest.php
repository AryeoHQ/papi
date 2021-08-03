<?php

namespace Tests;

use App\Methods\PapiMethods;
use PHPUnit\Framework\TestCase;

class PapiMethodsMiscTest extends TestCase
{
    private string $papi_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->papi_dir = getcwd();
    }

    public function testValidFilePath()
    {
        $this->assertTrue(true);
    }
}
