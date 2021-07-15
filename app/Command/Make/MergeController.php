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
        $this->arguments = [
            ['api', 'name of the api', 'Aryeo'],
            ['version', 'highest version to include in the merge', '2021-06-17'],
        ];
        $this->parameters = [
            ['pdir', 'project directory', '/Users/jdoe/Dev/aryeo'],
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
            $api = $args[0];
            $version = $args[1];
            $pdir = $this->getParam('pdir');
            $this->mergeVersions($pdir, $api, $version);
        } else {
            $this->printCommandHelp();
        }
    }

    public function mergeVersions($pdir, $api, $version)
    {
        $computed_properties = [];
        $merge_versions = PapiMethods::versionsEqualToOrBelow($pdir, $api, $version);

        if (count($merge_versions) === 0) {
            $this->getPrinter()->out('error: no versions to merge', 'error');
            $this->getPrinter()->newline();

            return;
        }

        foreach ($merge_versions as $merge_version) {
            $version_file_path = PapiMethods::specFile($pdir, $api, $merge_version);
            $json = PapiMethods::readJsonFromFile($version_file_path);

            if ($json['paths']) {
                foreach ($json['paths'] as $path_key => $path) {
                    foreach ($path as $propetry_key => $propetry) {
                        $computed_path = '[paths]['.$path_key.']['.$propetry_key.']';

                        if (!isset($computed_properties[$computed_path])) {
                            $computed_properties[$computed_path] = $propetry;
                        }
                    }
                }
            }
        }

        $version_file_path = PapiMethods::specFile($pdir, $api, $version);
        $json = PapiMethods::readJsonFromFile($version_file_path);

        // overwrite computed properties
        foreach ($computed_properties as $computed_key => $computed_value) {
            $json = PapiMethods::setNestedValue($json, $computed_key, $computed_value);
        }

        PapiMethods::writeJsonToFile($json, PapiMethods::specRootDirectory($pdir).'/out/Aryeo/Aryeo.MERGED.json');
    }
}
