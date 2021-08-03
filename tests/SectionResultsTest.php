<?php

namespace Tests;

use App\Models\SectionResults;
use PHPUnit\Framework\TestCase;

class SectionResultsTest extends TestCase
{
    public function testSectionResultInit()
    {
        $section_result = new SectionResults('Section', []);

        $this->assertEquals($section_result->name, 'Section');
        $this->assertEquals($section_result->errors, []);

        $section_result = new SectionResults('New', [
            'error_1',
            'error_2',
        ]);

        $this->assertEquals($section_result->name, 'New');
        $this->assertCount(2, $section_result->errors);
    }
}
