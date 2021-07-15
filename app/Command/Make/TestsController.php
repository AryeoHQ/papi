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
        $this->arguments = [
            ['api', 'name of the api', 'Aryeo'],
        ];
        $this->parameters = [
            ['pdir', 'project directory', '/Users/jdoe/Dev/aryeo'],
        ];
        $this->notes = ['Tests will be created in the ../../tests/Spec directory.'];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $api = $args[0];
            $pdir = $this->getParam('pdir');
            $this->handleMissingTests($pdir, $api);
        } else {
            $this->printCommandHelp();
        }
    }

    public function handleMissingTests($pdir, $api)
    {
        [$required_paths, $found_paths] = $this->determineMissingTestPaths($pdir, $api);
        $missing_paths = array_diff($required_paths, $found_paths);

        $this->createMissingTests($api, $missing_paths);
        $this->fillMissingMethods($pdir, $api);
    }

    public function determineMissingTestPaths($pdir, $api)
    {
        $spec_files = PapiMethods::specFiles($pdir, $api);

        $required = [];
        $found = [];

        // for each spec...
        foreach ($spec_files as $spec_file) {
            [$spec_name, $spec_version] = PapiMethods::specNameAndVersion($spec_file);

            $json_string = file_get_contents(PapiMethods::specsDirectory($pdir, $api).'/'.$spec_file);
            $json = json_decode($json_string, true);

            // for each path...
            foreach ($json['paths'] as $path => $path_json) {
                $path_segments = PapiMethods::specPathToSegments($path);
                $test_file_name = join('', $path_segments).'Test.php';
                $test_file_path = PapiMethods::specTestDirectory($pdir).'/'.$spec_name.'/v'.str_replace('-', '_', $spec_version).'/'.$test_file_name;
                $required[] = $test_file_path;
                if (realpath($test_file_path)) {
                    $found[] = $test_file_path;
                }
            }
        }

        return [$required, $found];
    }

    public function createMissingTests($api, $test_paths)
    {
        $template_test = file_get_contents('TemplateTest.php', true);

        foreach ($test_paths as $test_path) {
            $version = basename(dirname($test_path));
            $namespace = $api.'\v'.str_replace('-', '_', $version);
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
            PapiMethods::writeFile($template_test_copy, $test_path);
        }
    }

    public function fillMissingMethods($pdir, $api)
    {
        $spec_files = PapiMethods::specFiles($pdir, $api);

        // for each spec...
        foreach ($spec_files as $spec_file) {
            [$spec_name, $spec_version] = PapiMethods::specNameAndVersion($spec_file);

            $json_string = file_get_contents(PapiMethods::specsDirectory($pdir, $api).'/'.$spec_file);
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
                $test_file_path = PapiMethods::specTestDirectory($pdir).'/'.$spec_name.'/v'.str_replace('-', '_', $spec_version).'/'.$test_file_name;
                $test_content = file_get_contents($test_file_path, true);
                $test_content = str_replace('{{METHODS}}', implode("\n\n", $method_contents), $test_content);

                // create directory if it doesn't exist
                if (!file_exists(dirname($test_file_path))) {
                    mkdir(dirname($test_file_path), 0777, true);
                }

                // write file
                PapiMethods::writeFile($test_content, $test_file_path);
            }
        }
    }
}
