<?php

namespace App\Command\Clean;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class SpecController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'clean a spec file';
        $this->parameters = [
            ['format', 'spec format, defaults to JSON (JSON|YAML)', 'JSON', false],
            ['s_path', 'path to spec file', '/examples/reference/PetStore/PetStore.2021-07-23.json', true],
            ['r_path', 'path to responses JSON file', '/examples/responses.json', true],
            ['rm_path', 'path to response mappings JSON file', '/examples/response-mappings.json', true],
        ];
        $this->notes = [
            'Cleaning a spec inserts standard error responses for known status',
            'codes. Use the response mappings JSON file to map responses to HTTP verbs.',
            'The \"*\" key may be used to match any HTTP verb.',
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spec_path = $this->getParam('s_path');
            $responses_path = $this->getParam('r_path');
            $response_mappings_path = $this->getParam('rm_path');
            $this->cleanSpec($spec_path, $responses_path, $response_mappings_path);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanSpec($spec_file_path, $responses_path, $response_mappings_path)
    {
        if (!PapiMethods::validFilePath($spec_file_path)) {
            $this->printFileNotFound($spec_file_path);

            return;
        }

        if (!PapiMethods::validFilePath($responses_path)) {
            $this->printFileNotFound($responses_path);

            return;
        }

        if (!PapiMethods::validFilePath($response_mappings_path)) {
            $this->printFileNotFound($response_mappings_path);

            return;
        }

        $array = PapiMethods::readSpecFile($spec_file_path);
        $responses_array = PapiMethods::readSpecFile($responses_path);
        $response_mappings_array = PapiMethods::readSpecFile($response_mappings_path);
        $response_mappings_array = array_change_key_case($response_mappings_array, CASE_UPPER);

        // for each path...
        foreach ($array['paths'] as $path_key => $path) {
            foreach ($path as $method_key => $method_key_obj) {
                if ($method_key !== 'parameters') {
                    foreach ($array['paths'][$path_key][$method_key]['responses'] as $response) {
                        $http_verb = strtoupper($method_key);
                        $verb_is_mapped = isset($response_mappings_array[$http_verb]);

                        if ($verb_is_mapped) { // is this HTTP verb defined in response mappings JSON file?
                            $mapped_status_codes = $response_mappings_array[$http_verb];
                        } elseif (isset($response_mappings_array['*'])) { // is there a catch-all defined?
                            $mapped_status_codes = $response_mappings_array['*'];
                        } else { // verb is unmapped...
                            $mapped_status_codes = [];
                        }

                        foreach ($mapped_status_codes as $status_code) {
                            $array['paths'][$path_key][$method_key]['responses'][$status_code] = $responses_array[$status_code];
                        }
                    }
                }
            }
        }

        PapiMethods::writeSpecFile($array, $spec_file_path, $this->getFormat());
    }
}
