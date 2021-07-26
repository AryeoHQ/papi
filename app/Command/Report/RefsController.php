<?php

namespace App\Command\Report;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class RefsController extends PapiController
{
    protected $unreferenced_models = [];

    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'report out-of-date refs';
        $this->parameters = [
            ['s_path', 'path to spec file', '/examples/reference/PetStore/PetStore.2021-07-23.json', true],
            ['m_dir', 'models directory', '/examples/models', true]
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spec_path = $this->getParam('s_path');
            $models_dir = $this->getParam('m_dir');
            $this->checkAllRefs($spec_path, $models_dir);
        } else {
            $this->printCommandHelp();
        }
    }

    public function checkAllRefs($spec_path, $models_dir)
    {
        $errors = [];
        $unreferenced_errors = [];

        // get version from spec
        $json = PapiMethods::readJsonFromFile($spec_path);
        if ($json && isset($json['info']['version'])) {
            $version = $json['info']['version'];
        } else {
            $this->getPrinter()->out('👎 FAIL: Spec file does not contain valid version.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }
        
        // all models start as unreferenced
        $this->unreferenced_models = PapiMethods::models($models_dir . DIRECTORY_SEPARATOR . $version);

        // check model $refs...
        $errors = array_merge($errors, $this->checkModelRefs($spec_path, $models_dir, $version));

        // check spec $refs...
        $errors = array_merge($errors, $this->checkSpecRefs($spec_path, $models_dir, $version));

        // check unreferenced models...
        if (count($this->unreferenced_models) > 0) {
            foreach ($this->unreferenced_models as $unreferenced_model) {
                $unreferenced_errors[] = $unreferenced_model."\n\n";
            }
        }

        // exit based on errors
        if (count($errors) === 0 && count($unreferenced_errors) === 0) {
            $this->getPrinter()->out('👍 PASS: All $refs looking good.', 'success');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(0);
        } else {
            foreach ($errors as $error) {
                $this->getPrinter()->out('👎 FAIL: Bad $ref.', 'error');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
                $this->getPrinter()->rawOutput($error);
            }

            foreach ($unreferenced_errors as $error) {
                $this->getPrinter()->out('👎 FAIL: Unreferenced Model Detected.', 'error');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
                $this->getPrinter()->rawOutput($error);
            }

            exit(-1);
        }
    }

    public function checkRef($models_dir, $file, $container_version, $valid_versions, $key, $value)
    {
        $errors = [];

        // extract $ref filename and model name
        $temp_value = $value;
        $split_value = explode(DIRECTORY_SEPARATOR, $temp_value);
        $ref_file_name = array_pop($split_value);
        $temp_value = $ref_file_name;
        $split_value = explode('.', $ref_file_name);
        $ref_name = array_shift($split_value);
        $ref_name_check = $ref_name.'.json';

        // extract $ref version
        $matches = [];
        preg_match('/([0-9]+)\-([0-9]+)\-([0-9]+)/', $value, $matches);
        if (count($matches) === 0) {
            $ref_version = $container_version;
        } else {
            $ref_version = $matches[0];
        }

        // mark model as referenced
        $check_name = $ref_version.DIRECTORY_SEPARATOR.$ref_name;
        if (in_array($check_name, $this->unreferenced_models, true)) {
            unset($this->unreferenced_models[$check_name]);
        }

        // see if the current $ref even exists!
        $current_path_check = $models_dir.DIRECTORY_SEPARATOR.$ref_version.DIRECTORY_SEPARATOR.$ref_name_check;
        if (!file_exists($current_path_check)) {
            $error = 'File Path: '.$file;
            $error = $error."\nReference Path: ".$key;
            $error = $error."\nCurrent Value: ".$ref_version.DIRECTORY_SEPARATOR.$ref_file_name." (DNE!)\n\n";
            $errors[] = $error;

            return $errors;
        }

        // other versions where model might live...
        $versions_to_check = array_filter($valid_versions, function ($version) use ($ref_version) {
            return version_compare($version, $ref_version) > 0;
        });

        // see if there is a newer $ref...
        foreach ($versions_to_check as $version_to_check) {
            $ref_path_check = $models_dir.DIRECTORY_SEPARATOR.$version_to_check.DIRECTORY_SEPARATOR.$ref_name_check;
            
            if (file_exists($ref_path_check)) {
                $error = 'File Path: '.$file;
                $error = $error."\nReference Path: ".$key;
                $error = $error."\nCurrent Value: ".$ref_version.DIRECTORY_SEPARATOR.$ref_file_name;
                $error = $error."\nRecommended Value: ".$version_to_check.DIRECTORY_SEPARATOR.$ref_name_check."\n\n";
                $errors[] = $error;
                break;
            }
        }

        return $errors;
    }

    public function checkModelRefs($spec_file_path, $models_dir, $version)
    {
        $errors = [];
        $spec_dir = dirname($spec_file_path);
 
        // for each model file...
        foreach (PapiMethods::jsonFilesInDir($models_dir) as $model_file_name) {
            $model_path = $models_dir . DIRECTORY_SEPARATOR . $model_file_name;
            $model_json = PapiMethods::readJsonFromFile($model_path);
            $model_version = basename(dirname($model_path));
            $check_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $model_version);
 
            // for each $ref...
            foreach (PapiMethods::arrayFindRecursive($model_json, '$ref') as $result) {
                $ref_errors = $this->checkRef($models_dir, $model_path, $model_version, $check_versions, $result['path'], $result['value']);
                $errors = array_merge($errors, $ref_errors);
            }
        }

        return $errors;
    }

    public function checkSpecRefs($spec_file_path, $models_dir, $version)
    {
        $errors = [];

        $spec_dir = dirname($spec_file_path);
        $valid_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $version);
        
        $json = PapiMethods::readJsonFromFile($spec_file_path);

        if ($json) {
            // for each $ref...
            foreach (PapiMethods::arrayFindRecursive($json, '$ref') as $result) {
                $ref_errors = $this->checkRef($models_dir, $spec_file_path, '', $valid_versions, $result['path'], $result['value']);
                $errors = array_merge($errors, $ref_errors);
            }
        } else {
            $error = "**File DNE!**\n\n";
            $error = $error.'Path: '.$spec_file_path;
            $error = $error."\n\n";
            $errors[] = $error;
        }

        return $errors;
    }
}
