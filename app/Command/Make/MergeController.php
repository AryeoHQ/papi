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
            ['format', 'spec format, defaults to JSON (JSON|YAML)', 'JSON', false],
            ['s_dir', 'spec directory', '/examples/reference/PetStore', true],
            ['s_prefix', 'spec prefix', 'PetStore (e.g. PetStore.2021-07-23.json)', true],
            ['version', 'highest version to include in the merge', '2021-07-23', true],
            ['out_path', 'write path for merged api spec', '/examples/out/PetStore/PetStore.MERGED.json', true],
        ];
        $this->notes = [
            'When API versions are merged together, the most recent version of',
            'each named operation and method combination are used.',
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
        $format = $this->getFormat();

        // ensure target spec exists
        $version_file_found = false;
        $valid_extensions = PapiMethods::validExtensions($format);
        foreach ($valid_extensions as $extension) {
            $version_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_prefix . '.' . $version . '.' . $extension;

            if (PapiMethods::validFilePath($version_file_path)) {
                $version_file_found = true;
                break;
            }
        }
        if (!$version_file_found) {
            $version_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_prefix . '.' . $version . '.(' . implode("|", $valid_extensions) . ')';
            $this->printFileNotFound($version_file_path);
            return;
        }

        // determine merge versions
        $computed_properties = [];
        $merge_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $version, $format);
        if (count($merge_versions) === 0) {
            $this->getPrinter()->out('error: no versions to merge', 'error');
            $this->getPrinter()->newline();
            return;
        }

        // get computed properties
        foreach ($merge_versions as $merge_version) {
            $spec_file_path = '';

            foreach ($valid_extensions as $extension) {
                $spec_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_prefix . '.' . $merge_version . '.' . $extension;
                if (PapiMethods::validFilePath($spec_file_path)) {
                    break;
                }
            }

            $array = PapiMethods::readSpecFile($spec_file_path);

            if ($array['paths']) {
                foreach ($array['paths'] as $path_key => $path) {
                    foreach ($path as $property_key => $property) {
                        $computed_path = '[paths]['.$path_key.']['.$property_key.']';
                        if (!isset($computed_properties[$computed_path])) {
                            $computed_properties[$computed_path] = $property;
                        }
                    }
                }
            }
        }
        
        $array = PapiMethods::readSpecFile($version_file_path);

        // overwrite computed properties
        foreach ($computed_properties as $computed_key => $computed_value) {
            $array = PapiMethods::setNestedValue($array, $computed_key, $computed_value);
        }

        PapiMethods::writeSpecFile($array, $out_path, $format);
    }
}
