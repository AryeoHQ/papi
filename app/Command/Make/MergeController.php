<?php

namespace App\Command\Make;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class MergeController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'make a merged api spec by squashing versions together';
        $this->parameters = [
            ['s_dir', 'spec directory', '/examples/reference/PetStore', true],
            ['s_prefix', 'spec prefix', 'PetStore (e.g. PetStore.2021-07-23.json)', true],
            ['version', 'highest version to include in the merge', '2021-07-23', true],
            ['out_path', 'write path for merged api spec', '/examples/out/PetStore/PetStore.MERGED.json', true],
        ];
        $this->notes = [
            'When API versions are merged together, the most recent version of',
            'each named route and method combination are used.',
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spec_dir = $this->getParam('s_dir');
            $spec_prefix = $this->getParam('s_prefix');
            $version = $this->getParam('version');
            $out_path = $this->getParam('out_path');
            $this->mergeVersions($spec_dir, $spec_prefix, $version, $out_path);
        } else {
            $this->printCommandHelp();
        }
    }

    public function mergeVersions($spec_dir, $spec_prefix, $version, $out_path)
    {
        $version_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_prefix . '.' . $version . '.json';
        if (!PapiMethods::validPath($version_file_path)) {
            $this->printFileNotFound($version_file_path);
            return;
        }

        $computed_properties = [];
        $merge_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $version);
        
        if (count($merge_versions) === 0) {
            $this->getPrinter()->out('error: no versions to merge', 'error');
            $this->getPrinter()->newline();
            return;
        }

        foreach ($merge_versions as $merge_version) {
            $spec_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_prefix . '.' . $merge_version . '.json';
            $json = PapiMethods::readJsonFromFile($spec_file_path);

            if ($json['paths']) {
                foreach ($json['paths'] as $path_key => $path) {
                    foreach ($path as $property_key => $property) {
                        $computed_path = '[paths]['.$path_key.']['.$property_key.']';

                        if (!isset($computed_properties[$computed_path])) {
                            $computed_properties[$computed_path] = $property;
                        }
                    }
                }
            }
        }
        
        $json = PapiMethods::readJsonFromFile($version_file_path);

        // overwrite computed properties
        foreach ($computed_properties as $computed_key => $computed_value) {
            $json = PapiMethods::setNestedValue($json, $computed_key, $computed_value);
        }

        PapiMethods::writeJsonToFile($json, $out_path);
    }
}
