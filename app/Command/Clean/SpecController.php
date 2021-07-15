<?php

namespace App\Command\Clean;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class SpecController extends PapiController
{
    protected const errors = [];

    public function __construct()
    {
        $this->errors = [
            '404' => [ // not found ==> error
                'description' => 'ApiError',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '../../models/2021-06-17/ApiError.json',
                        ],
                    ],
                ],
            ],
            '409' => [ // conflict ==> error
                'description' => 'ApiError',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '../../models/2021-06-17/ApiError.json',
                        ],
                    ],
                ],
            ],
            '422' => [ // unprocessably entity ==> fail
                'description' => 'ApiFail',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '../../models/2021-06-17/ApiFail.json',
                        ],
                    ],
                ],
            ],
            '500' => [ // internal server error ==> error
                'description' => 'ApiError',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '../../models/2021-06-17/ApiError.json',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'clean a spec file';
        $this->parameters = [
            ['spath', 'path to spec file', '/Users/jdoe/Dev/aryeo/spec.json'],
        ];
        $this->notes = [
            'Cleaning a spec inserts standard error responses for known status',
            'codes.',
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spath = $this->getParam('spath');
            $this->cleanSpec($spath);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanSpec($spec_file_path)
    {
        if (empty($spec_file_path) || !file_exists($spec_file_path) || !is_file($spec_file_path)) {
            $this->getPrinter()->out('error: unable to find spec file', 'error');
            $this->getPrinter()->newline();
            return;
        }

        $json = PapiMethods::readJsonFromFile($spec_file_path);

        // for each path...
        foreach ($json['paths'] as $path_key => $path) {
            foreach ($path as $method_key => $method) {
                if ($method_key !== 'parameters') {
                    $json['paths'][$path_key][$method_key]['responses']['404'] = $this->errors['404'];

                    if ($method_key !== 'get') {
                        $json['paths'][$path_key][$method_key]['responses']['409'] = $this->errors['409'];
                    }

                    $json['paths'][$path_key][$method_key]['responses']['422'] = $this->errors['422'];
                    $json['paths'][$path_key][$method_key]['responses']['500'] = $this->errors['500'];
                }
            }
        }

        PapiMethods::writeJsonToFile($json, $spec_file_path);
    }
}
