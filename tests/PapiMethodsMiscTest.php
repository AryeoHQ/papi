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

    public function testFormatOperationKey()
    {
        $this->assertEquals(
            'GET /hello',
            PapiMethods::formatOperationKey('[paths][/hello][get]')
        );

        $this->assertEquals(
            'GET /disc/{disc_id}',
            PapiMethods::formatOperationKey('[paths][/disc/{disc_id}][get]')
        );
    }

    public function testFormatEnumKey()
    {
        $key = '[content][application/json][schema][properties][status][enum]';

        $this->assertEquals(
            'status.enum',
            PapiMethods::formatEnumKey($key)
        );
    }

    public function testMatchingOperationKeys()
    {
        $array_a = [
            'paths' => [
                '/hello' => [
                    'get' => [
                        'description' => ''
                    ]
                ]
            ]
        ];

        $array_b = [
            'paths' => [
                '/hello' => [
                    'get' => [
                        'description' => ''
                    ]
                ],
                '/goodbye' => [
                    'get' => [
                        'description' => ''
                    ]
                ]
            ]
        ];

        $results = [];

        foreach (PapiMethods::matchingOperationKeys($array_a, $array_b) as $index => $result) {
            $results[] = $result;
        }

        $this->assertContains('[paths][/hello][get]', $results);
        $this->assertNotContains('[paths][/goodbye][get]', $results);
    }

    public function testFullyQualifiedClassName()
    {
        $this->assertEquals(
            'Tests\Spec\PetStore\2021_07_23\HelloTest',
            PapiMethods::fullyQualifiedClassName(
                '/Hello',
                'PetStore',
                '2021-07-23'
            )
        );

        $this->assertEquals(
            'Tests\Spec\PetStore\2021_07_23\DiscDiscIdTest',
            PapiMethods::fullyQualifiedClassName(
                '/Disc/{Disc_Id}',
                'PetStore',
                '2021-07-23'
            )
        );
    }

    public function testModels()
    {
        $models_dir = $this->papi_dir.'/examples/models/2021-07-23';

        $results = PapiMethods::models($models_dir, 'json');

        $this->assertCount(8, $results);
        $this->assertContains('2021-07-23/Address', $results);
        $this->assertNotContains('2021-07-24/Order', $results);
    }

    public function testOperations()
    {
        $spec_file = $this->papi_dir.'/examples/reference/PetStore/PetStore.2021-07-23.json';

        $results = PapiMethods::operationsKeys($spec_file);

        $this->assertCount(19, $results);
        $this->assertContains('PUT /pet', $results);
        $this->assertNotContains('GET /listing', $results);
    }

    public function testOperationsFromArray()
    {
        $array = [
            'paths' => [
                '/hello' => [
                    'get' => [
                        'description' => ''
                    ]
                ],
                '/goodbye' => [
                    'get' => [
                        'description' => ''
                    ]
                ]
            ]
        ];

        $results = PapiMethods::operationsFromArray($array);

        $this->assertCount(2, $results);
        $this->assertContains('GET /hello', $results);
        $this->assertNotContains('GET /salutation', $results);
    }

    public function testGetNestedValue()
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

        $this->assertEquals(
            'low-level',
            PapiMethods::getNestedValue($array, '[obj][obj][description]')
        );
    }

    public function testSetNestedValue()
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

        $array = PapiMethods::setNestedValue($array, '[obj][obj][description]', 'winner');

        $this->assertEquals(
            'winner',
            PapiMethods::getNestedValue($array, '[obj][obj][description]')
        );
    }

    public function testSortItemsInDiff()
    {
        $this->assertEquals(-1, PapiMethods::sortItemsInDiff('/apple', '/banana'));
        $this->assertEquals(1, PapiMethods::sortItemsInDiff('/banana', '/apple'));
    }

    public function testSpecPathToSegments()
    {
        $path = '/listing/{listing_id}';

        $this->assertEquals(
            [
                'Listing',
                'ListingId'
            ],
            PapiMethods::specPathToSegments($path)
        );
    }

    public function testVersionsEqualToOrBelow()
    {
        $spec_dir = $this->papi_dir.'/examples/reference/PetStore';

        $this->assertEquals(
            [
                '2021-07-24',
                '2021-07-23'
            ],
            PapiMethods::versionsEqualToOrBelow($spec_dir, '2021-07-24', 'json')
        );
    }

    public function testVersionsBetween()
    {
        $spec_dir = $this->papi_dir.'/examples/reference/PetStore';

        $this->assertEquals(
            [],
            PapiMethods::versionsBetween($spec_dir, '2021-07-23', false, '2021-07-24', false, 'json')
        );

        $this->assertEquals(
            [
                '2021-07-23'
            ],
            PapiMethods::versionsBetween($spec_dir, '2021-07-23', true, '2021-07-24', false, 'json')
        );

        $this->assertEquals(
            [
                '2021-07-24'
            ],
            PapiMethods::versionsBetween($spec_dir, '2021-07-23', false, '2021-07-24', true, 'json')
        );

        $this->assertEquals(
            [
                '2021-07-23',
                '2021-07-24'
            ],
            PapiMethods::versionsBetween($spec_dir, '2021-07-23', true, '2021-07-24', true, 'json')
        );
    }
}
