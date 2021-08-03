<?php

namespace Tests;

use App\Methods\PapiMethods;
use PHPUnit\Framework\TestCase;

class PapiMethodsArrayTest extends TestCase
{
    public function testArrayFindRecursive()
    {
        $array = [
            'description' => 'top-level',
            'obj' => [
                'description' => 'mid-level',
                'obj' => [
                    'description' => 'low-level'
                ]
            ]
        ];
                
        foreach (PapiMethods::arrayFindRecursive($array, 'description') as $index => $result) {
            if ($index == 0) {
                $this->assertEquals(
                    $result,
                    [
                        'path' => '[description]',
                        'value' => 'top-level'
                    ]
                );
            } elseif ($index == 1) {
                $this->assertEquals(
                    $result,
                    [
                        'path' => '[obj][description]',
                        'value' => 'mid-level'
                    ]
                );
            } elseif ($index == 2) {
                $this->assertEquals(
                    $result,
                    [
                        'path' => '[obj][obj][description]',
                        'value' => 'low-level'
                    ]
                );
            }
        }
    }

    public function testArrayKeysRecursive()
    {
        $array = [
            'description' => 'top-level',
            'obj' => [
                'description' => 'mid-level',
                'obj' => [
                    'description' => 'low-level'
                ]
            ]
        ];

        $result = PapiMethods::arrayKeysRecursive($array);

        $this->assertCount(5, $result);
        
        $this->assertCount(
            3,
            array_filter($result, function ($item) {
                return $item == 'description';
            })
        );

        $this->assertCount(
            2,
            array_filter($result, function ($item) {
                return $item == 'obj';
            })
        );
    }
}
