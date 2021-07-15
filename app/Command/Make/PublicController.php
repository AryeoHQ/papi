<?php

namespace App\Command\Make;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class PublicController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'make public api spec from a de-referenced spec';
        $this->parameters = [
            ['spath', 'path to spec file', '/Users/jdoe/Dev/aryeo/spec.json'],
            ['ospath', 'write path for public api spec', '/Users/jdoe/Dev/aryeo/spec-public.json'],
            ['opath', 'path to overrides file', '/tmp/overrides.json'],
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spath = $this->getParam('spath');
            $ospath = $this->getParam('ospath');
            $overrides_path = $this->getParam('opath');
            $this->preparePublicSpec($spath, $ospath, $overrides_path);
        } else {
            $this->printCommandHelp();
        }
    }

    public function preparePublicSpec($spath, $ospath, $overrides_path)
    {
        if (!$spath) {
            $this->getPrinter()->out('error: cannot find ' . $spath, 'error');
            $this->getPrinter()->newline();

            return;
        } else {
            $json = PapiMethods::readJsonFromFile($spath);
            $json = $this->removeInternalPaths($json);
            $json = $this->removeUnreferencedTags($json);
            $json = $this->makePathMethodAdjustments($json);
            $json = $this->applyPublicOverrides($json, $overrides_path);

            PapiMethods::writeJsonToFile($json, $ospath);
        }
    }

    public function removeInternalPaths($json)
    {
        $valid_methods = ['get', 'head', 'post', 'put', 'delete', 'connect', 'options', 'trace', 'patch'];

        if (isset($json['paths'])) {
            // for each path...
            foreach ($json['paths'] as $path_key => $path) {
                // for each method...
                foreach ($path as $method_key => $method) {
                    if (in_array($method_key, $valid_methods, true)) {
                        if (isset($method['x-internal'])) {
                            if ($method['x-internal'] === true) {
                                unset($json['paths'][$path_key][$method_key]);
                            }
                        }
                    }
                }

                // get updated json for path
                $path = $json['paths'][$path_key];

                // does path still have valid method?
                $path_has_valid_method = false;
                foreach ($path as $method_key => $method) {
                    if (in_array($method_key, $valid_methods, true)) {
                        $path_has_valid_method = true;
                    }
                }

                // if not, delete path
                if (!$path_has_valid_method) {
                    unset($json['paths'][$path_key]);
                }
            }
        }

        return $json;
    }

    public function removeUnreferencedTags($json)
    {
        $tags_to_keep = [];

        if (isset($json['paths']) && isset($json['tags'])) {
            // for each tag...
            foreach ($json['tags'] as $tag) {
                $tag_name = $tag['name'];
                $keep_tag = false;

                // for each path...
                foreach ($json['paths'] as $path_key => $path) {
                    // for each method...
                    foreach ($path as $method_key => $method) {
                        // does method contain tag?
                        if ($method['tags'] && in_array($tag_name, $method['tags'], true)) {
                            $keep_tag = true;
                        }
                    }
                }

                // if not, delete tag
                if ($keep_tag) {
                    $tags_to_keep[] = $tag;
                }
            }
        }

        $json['tags'] = $tags_to_keep;

        return $json;
    }

    public function makePathMethodAdjustments($json)
    {
        if (isset($json['paths'])) {
            // for each path...
            foreach ($json['paths'] as $path_key => $path) {
                // for each method...
                foreach ($path as $method_key => $method) {
                    if ($method['parameters']) {
                        $parameters_to_keep = [];
                        foreach ($method['parameters'] as $parameter) {
                            if ($parameter['name'] !== 'X-ARYEO-GROUP-UUID') {
                                $parameters_to_keep[] = $parameter;
                            }
                        }
                        $json['paths'][$path_key][$method_key]['parameters'] = $parameters_to_keep;
                    }
                }
            }
        }

        return $json;
    }

    public function applyPublicOverrides($json, $overrides_path)
    {
        $overrides = PapiMethods::readJsonFromFile($overrides_path);

        foreach ($overrides as $override_key => $override_value) {
            $json = PapiMethods::setNestedValue($json, $override_key, $override_value);
        }

        return $json;
    }
}
