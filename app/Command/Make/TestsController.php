<?php

namespace App\Command\Make;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class TestsController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'make missing spec tests given an api spec';
        $this->parameters = [
            ['p_dir', 'project directory', '/examples/stubbed-laravel-project', true],
            ['s_dir', 'spec directory', '/examples/reference/PetStore', true],
            ['t_path', 'path to test template', '/examples/TemplateTest.php', true]
        ];
        $this->notes = ['Tests will be created in the [p_dir]/tests/Spec directory.'];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $project_directory = $this->getParam('p_dir');
            $spec_dir = $this->getParam('s_dir');
            $test_template_path = $this->getParam('t_path');
            $this->handleMissingTests($project_directory, $spec_dir, $test_template_path);
        } else {
            $this->printCommandHelp();
        }
    }

    public function handleMissingTests($project_directory, $spec_dir, $test_template_path)
    {
        [$required_paths, $found_paths] = $this->determineMissingTestPaths($project_directory, $spec_dir);
        $missing_paths = array_diff($required_paths, $found_paths);

        $this->createMissingTests($missing_paths, $test_template_path);
        $this->fillMissingMethods($project_directory, $spec_dir);
    }

    public function determineMissingTestPaths($project_directory, $spec_dir)
    {
        $spec_files = PapiMethods::jsonFilesInDir($spec_dir);

        $required = [];
        $found = [];

        // for each spec...
        foreach ($spec_files as $spec_file) {
            [$spec_name, $spec_version] = PapiMethods::specNameAndVersion($spec_file);

            $json_string = file_get_contents($spec_dir.DIRECTORY_SEPARATOR.$spec_file);
            $json = json_decode($json_string, true);

            // for each path...
            foreach ($json['paths'] as $path => $path_json) {
                $path_segments = PapiMethods::specPathToSegments($path);
                $test_file_name = join('', $path_segments).'Test.php';
                $test_file_path = PapiMethods::specTestDirectory($project_directory).DIRECTORY_SEPARATOR.$spec_name.'/v'.str_replace('-', '_', $spec_version).DIRECTORY_SEPARATOR.$test_file_name;
                $required[] = $test_file_path;
                if (realpath($test_file_path)) {
                    $found[] = $test_file_path;
                }
            }
        }

        return [$required, $found];
    }

    public function createMissingTests($test_paths, $test_template_path)
    {
        $template_test = file_get_contents($test_template_path, true);

        foreach ($test_paths as $test_path) {
            $version = basename(dirname($test_path));
            $spec_name = basename(dirname($test_path, 2));
            $namespace = $spec_name.'\\'.str_replace('-', '_', $version);
            $class_name = basename($test_path, '.php');

            // replace template content...
            $template_test_copy = $template_test;
            $template_test_copy = str_replace('{{NAMESPACE}}', $namespace, $template_test_copy);
            $template_test_copy = str_replace('{{TEST_CLASS}}', $class_name, $template_test_copy);

            // create directory if it doesn't exist
            if (!file_exists(dirname($test_path))) {
                mkdir(dirname($test_path), 0777, true);
            }

            // write file
            PapiMethods::writeTextToFile($template_test_copy, $test_path);
        }
    }

    public function fillMissingMethods($project_directory, $spec_dir)
    {
        $spec_files = PapiMethods::jsonFilesInDir($spec_dir);

        // for each spec...
        foreach ($spec_files as $spec_file) {
            [$spec_name, $spec_version] = PapiMethods::specNameAndVersion($spec_file);

            $json_string = file_get_contents($spec_dir.DIRECTORY_SEPARATOR.$spec_file);
            $json = json_decode($json_string, true);

            $valid_methods = ['get', 'head', 'post', 'put', 'delete', 'connect', 'options', 'trace', 'patch'];

            // for each path...
            foreach ($json['paths'] as $path => $path_json) {
                $method_contents = [];
                $class_fqn = PapiMethods::fullyQualifiedClassName($path, $spec_name, $spec_version);

                // for each HTTP method...
                foreach ($path_json as $method => $method_json) {
                    if (in_array($method, $valid_methods, true)) {
                        foreach ($method_json['responses'] as $status_code => $response_json) {
                            if ($status_code >= 200 && $status_code <= 299) {
                                $test_method_name = 'test'.ucfirst($method).$status_code;

                                if (!method_exists($class_fqn, $test_method_name)) {
                                    $method_content = <<<EOD
                                        public function $test_method_name()
                                        {

                                        }
                                    EOD;
                                    $method_contents[] = $method_content;
                                }
                            }
                        }
                    }
                }

                $path_segments = PapiMethods::specPathToSegments($path);
                $test_file_name = join('', $path_segments).'Test.php';
                $test_file_path = PapiMethods::specTestDirectory($project_directory).DIRECTORY_SEPARATOR.$spec_name.'/v'.str_replace('-', '_', $spec_version).DIRECTORY_SEPARATOR.$test_file_name;
                $test_content = file_get_contents($test_file_path, true);
                $test_content = str_replace('{{METHODS}}', implode("\n\n", $method_contents), $test_content);

                // create directory if it doesn't exist
                if (!file_exists(dirname($test_file_path))) {
                    mkdir(dirname($test_file_path), 0777, true);
                }

                // write file
                PapiMethods::writeTextToFile($test_content, $test_file_path);
            }
        }
    }
}
