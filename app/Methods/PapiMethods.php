<?php

namespace App\Methods;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class PapiMethods
{
    /*
     * Directories
     */

    public static function specRootDirectory($pdir)
    {
        $normalized_pdir = '/'.ltrim($pdir, '/');

        return $normalized_pdir.'/spec';
    }

    public static function specTestDirectory($pdir)
    {
        $normalized_pdir = '/'.ltrim($pdir, '/');

        return $normalized_pdir.'/tests/Spec';
    }

    public static function modelsDirectory($pdir)
    {
        return PapiMethods::specRootDirectory($pdir).'/models';
    }

    public static function specsDirectory($pdir, $api)
    {
        return PapiMethods::specRootDirectory($pdir).'/reference'.'/'.$api;
    }

    public static function outDirectory($pdir, $api)
    {
        return PapiMethods::specRootDirectory($pdir).'/out'.'/'.$api;
    }

    /*
     * Files
     */

    public static function jsonFilesInDir($dir)
    {
        if (file_exists($dir)) {
            $items = scandir($dir);

            if ($items) {
                return array_filter($items, function ($item) {
                    if (is_dir($item) || pathinfo($item)['extension'] !== 'json') {
                        return false;
                    } else {
                        return strlen(''.basename($item, '.json')) > 0;
                    }
                });
            }
        }

        return [];
    }

    public static function modelFiles($pdir, $version)
    {
        $versioned_models_dir = PapiMethods::modelsDirectory($pdir).'/'.$version;

        return PapiMethods::jsonFilesInDir($versioned_models_dir);
    }

    public static function modelFilePath($pdir, $model_file_name, $version)
    {
        return PapiMethods::modelsDirectory($pdir).'/'.$version.'/'.$model_file_name;
    }

    public static function specFiles($pdir, $api)
    {
        $versioned_spec_dir = PapiMethods::specsDirectory($pdir, $api);

        return PapiMethods::jsonFilesInDir($versioned_spec_dir);
    }

    public static function specFile($pdir, $api, $version)
    {
        $spec_file = PapiMethods::specsDirectory($pdir, $api).'/'.$api.'.'.$version.'.json';

        return (file_exists($spec_file)) ? $spec_file : '';
    }

    public static function derefSpecFile($pdir, $api, $version)
    {
        $spec_file = PapiMethods::outDirectory($pdir, $api).'/'.$api.'-deref.'.$version.'.json';

        return (file_exists($spec_file)) ? $spec_file : '';
    }

    public static function specNameAndVersion($spec_file)
    {
        $file_name = ''.basename($spec_file, '.json');
        $split_index = strpos($file_name, '.');
        $spec_name = substr($file_name, 0, $split_index);
        $spec_version = substr($file_name, $split_index + 1);

        return [$spec_name, $spec_version];
    }

    /*
     * I/O
     */

    public static function readJsonFromFile($file_path)
    {
        if (realpath($file_path) && file_exists($file_path)) {
            $json_string = file_get_contents($file_path);

            return json_decode($json_string, true);
        } else {
            return false;
        }
    }

    public static function writeJsonToFile($json, $file_path)
    {
        // create write directory if DNE
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0777, true);
        }

        $file = fopen($file_path, 'w') or exit('Unable to open file!');

        $json_str_indented_by_4 = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $json_str_indented_by_2 = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $json_str_indented_by_4);

        fwrite($file, $json_str_indented_by_2);
        fclose($file);
    }

    public static function writeFile($contents, $file_path)
    {
        // create write directory if DNE
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0777, true);
        }

        $file = fopen($file_path, 'w') or exit('Unable to open file!');
        fwrite($file, $contents);
        fclose($file);
    }

    /*
     * Arrays
     */

    public static function arrayFindRecursive(array $haystack, $needle, $glue = '.')
    {
        $iterator = new RecursiveArrayIterator($haystack);
        $recursive = new RecursiveIteratorIterator(
            $iterator,
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($recursive as $key => $value) {
            // if the key matches our search
            if ($key === $needle) {
                // add the current key
                $keys = [$key];

                // loop up the recursive chain...
                for ($i = $recursive->getDepth() - 1; $i >= 0; --$i) {
                    array_unshift($keys, $recursive->getSubIterator($i)->key());
                }

                yield [
                    'path' => '['.str_replace('.', '][', implode($glue, $keys)).']',
                    'value' => $value,
                ];
            }
        }
    }

    public static function arrayKeysRecursive($array)
    {
        $keys = array_keys($array);

        foreach ($array as $i) {
            if (is_array($i)) {
                $keys = array_merge($keys, PapiMethods::arrayKeysRecursive($i));
            }
        }

        return $keys;
    }

    /*
     * Miscellaneous
     */

    public static function formatRouteKey($route_key)
    {
        $trimmed_key = substr($route_key, 1, -1);
        $parts = explode('][', $trimmed_key);

        return strtoupper($parts[2]).' '.$parts[1];
    }

    public static function formatEnumKey($property_key)
    {
        $trimmed_key = substr($property_key, 1, -1);
        $parts = explode('][', $trimmed_key);

        return join('.', array_slice($parts, 4));
    }

    public static function matchingRouteKeys($a_json, $b_json)
    {
        $a_routes = PapiMethods::routesFromJson($a_json, true);

        foreach ($a_routes as $route_key) {
            // is the route also in b?
            if (PapiMethods::getNestedValue($b_json, $route_key)) {
                yield $route_key;
            }
        }
    }

    public static function fullyQualifiedClassName($path, $spec_name, $spec_version)
    {
        $path_segments = PapiMethods::specPathToSegments($path);
        $class_name = join('', $path_segments).'Test';
        $class_namespace = 'Tests\Spec\\'.ucfirst($spec_name).'\v'.str_replace('-', '_', $spec_version);

        return $class_namespace.'\\'.$class_name;
    }

    public static function models($pdir, $version)
    {
        $models = [];

        foreach (PapiMethods::modelFiles($pdir, $version) as $model_file_name) {
            $mpath = PapiMethods::modelFilePath($pdir, $model_file_name, $version);

            $mdir = basename(dirname($mpath));
            $mname = basename($mpath, '.json');

            $mkey = $mdir.'/'.$mname;

            if (!isset($models[$mkey])) {
                $models[$mkey] = $mkey;
            }
        }

        return $models;
    }

    public static function routes($pdir, $api, $version)
    {
        $spec_file_path = PapiMethods::specFile($pdir, $api, $version);
        $json = PapiMethods::readJsonFromFile($spec_file_path);

        return PapiMethods::routesFromJson($json);
    }

    public static function routesFromJson($json, $path_format = false)
    {
        $routes = [];

        if (isset($json['paths'])) {
            $valid_methods = ['get', 'head', 'post', 'put', 'delete', 'connect', 'options', 'trace', 'patch'];

            // for each path...
            foreach ($json['paths'] as $path_key => $path) {
                // for each method...
                foreach ($path as $method_key => $method) {
                    if (in_array($method_key, $valid_methods, true)) {
                        if ($path_format) {
                            $key_and_value = '[paths]['.$path_key.']['.$method_key.']';
                            if (!isset($routes[$key_and_value])) {
                                $routes[$key_and_value] = $key_and_value;
                            }
                        } else {
                            $key_and_value = strtoupper($method_key).' '.$path_key;
                            if (!isset($routes[$key_and_value])) {
                                $routes[$key_and_value] = $key_and_value;
                            }
                        }
                    }
                }
            }
        }

        return $routes;
    }

    public static function getNestedValue($array, $key_path)
    {
        $key_path = explode('][', trim($key_path, '[]'));
        $reference = &$array;

        $value = '';
        foreach ($key_path as $key) {
            if (!isset($reference[$key])) {
                return '';
            }
            $reference = &$reference[$key];
        }
        $value = $reference;
        unset($reference);

        return $value;
    }

    public static function setNestedValue($array, $key_path, $value)
    {
        $key_path = explode('][', trim($key_path, '[]'));
        $reference = &$array;

        foreach ($key_path as $key) {
            if (!array_key_exists($key, $reference)) {
                $reference[$key] = [];
            }
            $reference = &$reference[$key];
        }
        $reference = $value;
        unset($reference);

        return $array;
    }

    public static function sortItemsInDiff($a, $b)
    {
        $a_index = strpos($a, '/');
        $b_index = strpos($b, '/');

        if ($a_index > 0 && $b_index > 0) {
            return (substr($a, $a_index) < substr($b, $b_index)) ? -1 : 1;
        } else {
            return ($a < $b) ? -1 : 1;
        }
    }

    public static function specPathToSegments($spec_file_path)
    {
        $path_only = substr($spec_file_path, 1);

        return array_map('ucfirst', preg_replace('/[\{\}]/', '', preg_split('/[\/\-]/', $path_only)));
    }

    public static function versionsEqualToOrBelow($pdir, $api, $version)
    {
        $spec_files = PapiMethods::specFiles($pdir, $api, $version);

        $spec_versions = array_map(function ($a) {
            $base_name = basename($a, '.json');

            return substr($base_name, strpos($base_name, '.') + 1);
        }, $spec_files);

        $filtered_versions = array_filter($spec_versions, function ($a) use ($version) {
            return version_compare($a, $version) < 1;
        });

        return array_reverse($filtered_versions);
    }

    public static function versionsAbove($pdir, $api, $version)
    {
        $spec_files = PapiMethods::specFiles($pdir, $api, $version);

        $spec_versions = array_map(function ($a) {
            $base_name = basename($a, '.json');

            return substr($base_name, strpos($base_name, '.') + 1);
        }, $spec_files);

        $filtered_versions = array_filter($spec_versions, function ($a) use ($version) {
            return version_compare($a, $version) > 0;
        });

        return $filtered_versions;
    }
}
