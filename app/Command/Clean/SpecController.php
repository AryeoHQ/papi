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
            ['s_path', 'path to spec file', '/examples/reference/PetStore/PetStore.2021-07-23.json', true],
            ['r_path', 'path to responses JSON file', '/examples/responses.json', true],
            ['rm_path', 'path to response mappings JSON file', '/examples/response-mappings.json', true]
        ];
        $this->notes = [
            'Cleaning a spec inserts standard error responses for known status',
            'codes. Use the response mappings JSON file to map responses to HTTP verbs.',
            'The \"*\" key may be used to match any HTTP verb.'
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
        if (!PapiMethods::validPath($spec_file_path)) {
            $this->printFileNotFound($spec_file_path);
            return;
        }

        if (!PapiMethods::validPath($responses_path)) {
            $this->printFileNotFound($responses_path);
            return;
        }

        if (!PapiMethods::validPath($response_mappings_path)) {
            $this->printFileNotFound($response_mappings_path);
            return;
        }

        $json = PapiMethods::readJsonFromFile($spec_file_path);
        $responses_json = PapiMethods::readJsonFromFile($responses_path);
        $response_mappings_json = PapiMethods::readJsonFromFile($response_mappings_path);
        $response_mappings_json = array_change_key_case($response_mappings_json, CASE_UPPER);

        // for each path...
        foreach ($json['paths'] as $path_key => $path) {
            foreach ($path as $method_key => $method_key_json) {
                if ($method_key !== 'parameters') {
                    foreach ($json['paths'][$path_key][$method_key]['responses'] as $status_code => $response) {
                        $http_verb = strtoupper($method_key);
                        $status_code_int = intval($status_code);
                        $verb_is_mapped = isset($response_mappings_json[$http_verb]);

                        if ($verb_is_mapped) { // is this HTTP verb defined in response mappings JSON file?
                            $mapped_status_codes = $response_mappings_json[$http_verb];
                        } elseif (isset($response_mappings_json['*'])) { // is there a catch-all defined?
                            $mapped_status_codes = $response_mappings_json['*'];
                        } else { // verb is unmapped...
                            $mapped_status_codes = [];
                        }

                        $valid_status_code = $status_code_int >= 100 && $status_code_int <= 599;
                        $valid_status_code_mapping = is_array($mapped_status_codes);

                        if ($valid_status_code && $valid_status_code_mapping) {
                            if (in_array($status_code, $mapped_status_codes) && isset($responses_json[$status_code])) {
                                $json['paths'][$path_key][$method_key]['responses'][$status_code] = $responses_json[$status_code];
                            }
                        }
                    }
                }
            }
        }

        PapiMethods::writeJsonToFile($json, $spec_file_path);
    }
}
