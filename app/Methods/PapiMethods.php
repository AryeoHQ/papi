<?php

namespace App\Methods;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class PapiMethods
{
    /*
     * Files
     */

    public static function isValidFile($path)
    {
        return !(empty($path) || !file_exists($path) || !is_file($path));
    }

    public static function specTestDirectory($project_dir)
    {
        $normalized_project_dir = '/'.ltrim($project_dir, '/');

        return $normalized_project_dir.'/tests/Spec';
    }

    public static function scandirRecursively($dir)
    {
        $result = [];
        foreach (scandir($dir) as $filename) {
            if ($filename[0] === '.') {
                continue;
            }
            $filePath = $dir . DIRECTORY_SEPARATOR . $filename;
            if (is_dir($filePath)) {
                foreach (PapiMethods::scandirRecursively($filePath) as $childFilename) {
                    $result[] = $filename . DIRECTORY_SEPARATOR . $childFilename;
                }
            } else {
                $result[] = $filename;
            }
        }
        return $result;
    }

    public static function jsonFilesInDir($dir)
    {
        if (file_exists($dir) && is_dir($dir)) {
            $items = PapiMethods::scandirRecursively($dir);
            
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

    public static function specNameAndVersion($spec_file)
    {
        $file_name = ''.basename($spec_file, '.json');
        $split_index = strpos($file_name, '.');
        $spec_name = substr($file_name, 0, $split_index);
        $spec_version = substr($file_name, $split_index + 1);

        return [$spec_name, $spec_version];
    }

    /*
     * Reading and Writing
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

    public static function writeTextToFile($text, $file_path)
    {
        // create write directory if DNE
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0777, true);
        }

        $file = fopen($file_path, 'w') or exit('Unable to open file!');
        fwrite($file, $text);
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
        $class_namespace = 'Tests\Spec\\'.ucfirst($spec_name).'\\'.str_replace('-', '_', $spec_version);

        return $class_namespace.'\\'.$class_name;
    }

    public static function models($models_dir)
    {
        $models = [];

        foreach (PapiMethods::jsonFilesInDir($models_dir) as $model_file_name) {
            $model_path = $models_dir . DIRECTORY_SEPARATOR . $model_file_name;
            $models_dir = basename(dirname($model_path));
            $model_name = basename($model_path, '.json');

            $model_key = $models_dir.DIRECTORY_SEPARATOR.$model_name;

            if (!isset($models[$model_key])) {
                $models[$model_key] = $model_key;
            }
        }

        return $models;
    }

    public static function routes($spec_file_path)
    {
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
            if (!isset($reference[$key])) {
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
        $spec_path = array_map('ucfirst', preg_replace('/[\{\}]/', '', preg_split('/[\/\-]/', $path_only)));

        $camel_to_pascal = function ($string) {
            return str_replace('_', '', ucwords($string, '_'));
        };
        $final_spec_path = array_map($camel_to_pascal, $spec_path);

        return $final_spec_path;
    }

    public static function versionsEqualToOrBelow($spec_dir, $version)
    {
        $spec_files = PapiMethods::jsonFilesInDir($spec_dir);

        $spec_versions = array_map(function ($a) {
            $base_name = basename($a, '.json');

            return substr($base_name, strpos($base_name, '.') + 1);
        }, $spec_files);

        $filtered_versions = array_filter($spec_versions, function ($a) use ($version) {
            return version_compare($a, $version) < 1;
        });

        return array_reverse($filtered_versions);
    }

    public static function versionsBetween($spec_dir, $version_floor, $include_floor, $version_ceiling, $include_ceiling)
    {
        $spec_files = PapiMethods::jsonFilesInDir($spec_dir);
        
        $spec_versions = array_map(function ($a) {
            $base_name = basename($a, '.json');

            return substr($base_name, strpos($base_name, '.') + 1);
        }, $spec_files);

        $filtered_versions = array_filter($spec_versions, function ($a) use ($version_floor, $include_floor, $version_ceiling, $include_ceiling) {
            if ($include_floor && $include_ceiling) {
                return version_compare($a, $version_floor) >= 0 && version_compare($a, $version_ceiling) <= 0;
            } elseif ($include_floor && !$include_ceiling) {
                return version_compare($a, $version_floor) >= 0 && version_compare($a, $version_ceiling) < 0;
            } elseif ($include_ceiling && !$include_floor) {
                return version_compare($a, $version_floor) > 0 && version_compare($a, $version_ceiling) <= 0;
            } else {
                return version_compare($a, $version_floor) > 0 && version_compare($a, $version_ceiling) < 0;
            }
        });

        return $filtered_versions;
    }
}
