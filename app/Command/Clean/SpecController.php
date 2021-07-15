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
            ['s_path', 'path to spec file', '/Users/john/Desktop/reference/Aryeo/Aryeo.2021-06-17.json'],
            ['r_path', 'path to responses json file', '/Users/john/Desktop/responses.json']
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
            $spec_path = $this->getParam('s_path');
            $responses_path = $this->getParam('r_path');
            $this->cleanSpec($spec_path, $responses_path);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanSpec($spec_file_path, $responses_path)
    {
        if (!PapiMethods::isValidFile($spec_file_path)) {
            $this->getPrinter()->out('👎 FAIL: Unable to find spec file.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            return;
        }

        if (!PapiMethods::isValidFile($responses_path)) {
            $this->getPrinter()->out('👎 FAIL: Unable to find responses json file.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            return;
        }

        $json = PapiMethods::readJsonFromFile($spec_file_path);
        $responses_json = PapiMethods::readJsonFromFile($responses_path);

        // for each path...
        foreach ($json['paths'] as $path_key => $path) {
            foreach ($path as $method_key => $method) {
                if ($method_key !== 'parameters') {
                    $json['paths'][$path_key][$method_key]['responses']['404'] = $responses_json['404'];

                    if ($method_key !== 'get') {
                        $json['paths'][$path_key][$method_key]['responses']['409'] = $responses_json['409'];
                    }

                    $json['paths'][$path_key][$method_key]['responses']['422'] = $responses_json['422'];
                    $json['paths'][$path_key][$method_key]['responses']['500'] = $responses_json['500'];
                }
            }
        }

        PapiMethods::writeJsonToFile($json, $spec_file_path);
    }
}
