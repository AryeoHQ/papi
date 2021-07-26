<?php

namespace App\Methods;

use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

class PapiMethods
{
    /*
     * Files
     */

    public static function validPath(string $path): bool
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

    public static function validExtension($format, $extension)
    {
        $extension = strtoupper($extension);

        if ($format == 'yaml') {
            return $extension == 'YML' || $extension == 'YAML';
        } elseif ($format == 'json') {
            return $extension == 'JSON';
        } else {
            return false;
        }
    }

    public static function specFilesInDir(string $dir, string $format)
    {
        if (file_exists($dir) && is_dir($dir)) {
            $items = PapiMethods::scandirRecursively($dir);
     
            if ($items) {
                return array_filter($items, function ($item) use ($format) {
                    $extension = pathinfo($item, PATHINFO_EXTENSION);
                    if (is_dir($item) || !PapiMethods::validExtension($format, $extension)) {
                        return false;
                    } else {
                        return strlen(''.basename($item, '.'.$extension)) > 0;
                    }
                });
            }
        }

        return [];
    }

    public static function specNameAndVersion($spec_file, $format)
    {
        $extension = pathinfo($spec_file, PATHINFO_EXTENSION);
        $file_name = ''.basename($spec_file, '.'.$extension);
        $split_index = strpos($file_name, '.');
        $spec_name = substr($file_name, 0, $split_index);
        $spec_version = substr($file_name, $split_index + 1);

        return [$spec_name, $spec_version];
    }

    /*
     * Reading and Writing
     */

    public static function readSpecFile($file_path)
    {
        if (realpath($file_path) && file_exists($file_path)) {
            $contents = file_get_contents($file_path);
            $extension = strtoupper(pathinfo($file_path, PATHINFO_EXTENSION));

            if ($extension == 'JSON') {
                return json_decode($contents, true);
            } elseif ($extension == 'YAML' || $extension == 'YML') {
                return Yaml::parse($contents);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public static function writeSpecFile($array, $file_path, $format)
    {
        if ($format == 'json') {
            PapiMethods::writeJsonSpecFile($array, $file_path);
        } elseif ($format == 'yaml') {
            PapiMethods::writeYamlSpecFile($array, $file_path);
        }
    }

    private static function writeJsonSpecFile($array, $file_path)
    {
        // create write directory if DNE
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0777, true);
        }

        $file = fopen($file_path, 'w') or exit('Unable to open file!');

        $json_str_indented_by_4 = json_encode($array, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $json_str_indented_by_2 = preg_replace('/^(  +?)\\1(?=[^ ])/m', '$1', $json_str_indented_by_4);

        fwrite($file, $json_str_indented_by_2);
        fclose($file);
    }

    private static function writeYamlSpecFile($array, $file_path)
    {
        // create write directory if DNE
        if (!file_exists(dirname($file_path))) {
            mkdir(dirname($file_path), 0777, true);
        }

        $yaml_str = Yaml::dump(
            $array,
            10,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );
        file_put_contents($file_path, $yaml_str);
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

    public static function matchingRouteKeys($a_array, $b_array)
    {
        $a_routes = PapiMethods::routesFromArray($a_array, true);

        foreach ($a_routes as $route_key) {
            // is the route also in b?
            if (PapiMethods::getNestedValue($b_array, $route_key)) {
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

    public static function models($models_dir, $format)
    {
        $models = [];

        foreach (PapiMethods::specFilesInDir($models_dir, $format) as $model_file_name) {
            $model_path = $models_dir . DIRECTORY_SEPARATOR . $model_file_name;
            $models_dir = basename(dirname($model_path));

            $extension = pathinfo($model_path, PATHINFO_EXTENSION);
            $model_name = basename($model_path, '.'.$extension);

            $model_key = $models_dir.DIRECTORY_SEPARATOR.$model_name;

            if (!isset($models[$model_key])) {
                $models[$model_key] = $model_key;
            }
        }

        return $models;
    }

    public static function routes($spec_file_path)
    {
        $array = PapiMethods::readSpecFile($spec_file_path);
        return PapiMethods::routesFromArray($array);
    }

    public static function routesFromArray($array, $path_format = false)
    {
        $routes = [];

        if (isset($array['paths'])) {
            $valid_methods = ['get', 'head', 'post', 'put', 'delete', 'connect', 'options', 'trace', 'patch'];

            // for each path...
            foreach ($array['paths'] as $path_key => $path) {
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

    public static function versionsEqualToOrBelow($spec_dir, $version, $format)
    {
        $spec_files = PapiMethods::specFilesInDir($spec_dir, $format);

        $spec_versions = array_map(function ($a) {
            $extension = pathinfo($a, PATHINFO_EXTENSION);
            $base_name = basename($a, '.'.$extension);

            return substr($base_name, strpos($base_name, '.') + 1);
        }, $spec_files);

        $filtered_versions = array_filter($spec_versions, function ($a) use ($version) {
            return version_compare($a, $version) < 1;
        });

        return array_reverse($filtered_versions);
    }

    public static function versionsBetween($spec_dir, $version_floor, $include_floor, $version_ceiling, $include_ceiling, $format)
    {
        $spec_files = PapiMethods::specFilesInDir($spec_dir, $format);
        
        $spec_versions = array_map(function ($a) {
            $extension = pathinfo($a, PATHINFO_EXTENSION);
            $base_name = basename($a, '.'.$extension);

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
