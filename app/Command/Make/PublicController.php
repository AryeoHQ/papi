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
            ['format', 'spec format, defaults to JSON (JSON|YAML)', 'JSON', false],
            ['s_path', 'path to spec file', '/examples/out/PetStore/PetStore.MERGED.json', true],
            ['o_path', 'path to overrides JSON file', '/examples/overrides.json', true],
            ['out_path', 'write path for public api spec', '/examples/out/PetStore/PetStore.PUBLIC.json', true],
            ['p_path', 'path to public customization JSON file', '/examples/public.json', false],
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spec_path = $this->getParam('s_path');
            $overrides_path = $this->getParam('o_path');
            $out_path = $this->getParam('out_path');

            $public_customizations = [];
            if ($this->hasParam('p_path')) {
                $public_customization_path = $this->getParam('p_path');
                if ($public_customization_path && !PapiMethods::validFilePath($public_customization_path)) {
                    $this->printFileNotFound($public_customization_path);
                    return;
                } else {
                    $public_customizations = PapiMethods::readSpecFile($public_customization_path);
                }
            }

            $this->preparePublicSpec($spec_path, $out_path, $overrides_path, $public_customizations);
        } else {
            $this->printCommandHelp();
        }
    }

    public function preparePublicSpec($spec_path, $out_path, $overrides_path, $public_customizations)
    {
        if (!PapiMethods::validFilePath($spec_path)) {
            $this->printFileNotFound($spec_path);
            return;
        }

        if (!PapiMethods::validFilePath($overrides_path)) {
            $this->printFileNotFound($overrides_path);
            return;
        }

        $array = PapiMethods::readSpecFile($spec_path);
        $array = $this->removeInternalPaths($array);
        $array = $this->removeUnreferencedTags($array);
        $array = $this->makePathMethodAdjustments($array, $public_customizations);
        $array = $this->applyPublicOverrides($array, $overrides_path);

        PapiMethods::writeSpecFile($array, $out_path, $this->getFormat());
    }

    public function removeInternalPaths($array)
    {
        $valid_methods = ['get', 'head', 'post', 'put', 'delete', 'connect', 'options', 'trace', 'patch'];

        if (isset($array['paths'])) {
            // for each path...
            foreach ($array['paths'] as $path_key => $path) {
                // for each method...
                foreach ($path as $method_key => $method) {
                    if (in_array($method_key, $valid_methods, true)) {
                        if (isset($method['x-internal'])) {
                            if ($method['x-internal'] === true) {
                                unset($array['paths'][$path_key][$method_key]);
                            }
                        }
                    }
                }

                // get updated content for path
                $path = $array['paths'][$path_key];

                // does path still have valid method?
                $path_has_valid_method = false;
                foreach ($path as $method_key => $method) {
                    if (in_array($method_key, $valid_methods, true)) {
                        $path_has_valid_method = true;
                    }
                }

                // if not, delete path
                if (!$path_has_valid_method) {
                    unset($array['paths'][$path_key]);
                }
            }
        }

        return $array;
    }

    public function removeUnreferencedTags($array)
    {
        $tags_to_keep = [];

        if (isset($array['paths']) && isset($array['tags'])) {
            // for each tag...
            foreach ($array['tags'] as $tag) {
                $tag_name = $tag['name'];
                $keep_tag = false;

                // for each path...
                foreach ($array['paths'] as $path_key => $path) {
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

        $array['tags'] = $tags_to_keep;

        return $array;
    }

    public function makePathMethodAdjustments($array, $public_customizations)
    {
        // which path parameters should be stripped?
        $strip_path_parameters = [];
        if (isset($public_customizations['strip-path-parameters'])) {
            $custom_strip_path_parameters = $public_customizations['strip-path-parameters'];
            if (is_array($custom_strip_path_parameters)) {
                $strip_path_parameters = $custom_strip_path_parameters;
            }
        }

        if (isset($array['paths'])) {
            // for each path...
            foreach ($array['paths'] as $path_key => $path) {
                // for each method...
                foreach ($path as $method_key => $method) {
                    if ($method['parameters']) {
                        $parameters_to_keep = [];
                        foreach ($method['parameters'] as $parameter) {
                            if (!in_array($parameter['name'], $strip_path_parameters)) {
                                $parameters_to_keep[] = $parameter;
                            }
                        }
                        $array['paths'][$path_key][$method_key]['parameters'] = $parameters_to_keep;
                    }
                }
            }
        }

        return $array;
    }

    public function applyPublicOverrides($array, $overrides_path)
    {
        $overrides = PapiMethods::readSpecFile($overrides_path);

        foreach ($overrides as $override_key => $override_value) {
            $array = PapiMethods::setNestedValue($array, $override_key, $override_value);
        }

        return $array;
    }
}
