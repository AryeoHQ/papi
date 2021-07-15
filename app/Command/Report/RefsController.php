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
        $this->description = 'report out-of-date refs found in an api\'s specs and models';
        $this->arguments = [
            ['api', 'name of the api', 'Aryeo'],
            ['version', 'version to inspect', '2021-06-17'],
        ];
        $this->parameters = [
            ['pdir', 'project directory', '/Users/jdoe/Dev/aryeo'],
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $api = $args[0];
            $version = $args[1];
            $pdir = $this->getParam('pdir');
            $this->checkAllRefs($pdir, $api, $version);
        } else {
            $this->printCommandHelp();
        }
    }

    public function checkAllRefs($pdir, $api, $version)
    {
        $errors = [];
        $unreferenced_errors = [];

        // all models start as unreferenced
        $this->unreferenced_models = PapiMethods::models($pdir, $version);

        // check model $refs...
        $errors = array_merge($errors, $this->checkModelRefs($pdir, $api, $version));

        // check spec $refs...
        $errors = array_merge($errors, $this->checkSpecRefs($pdir, $api, $version));

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

    public function checkRef($pdir, $file, $container_version, $valid_versions, $key, $value)
    {
        $errors = [];

        // extract $ref filename and model name
        $temp_value = $value;
        $split_value = explode('/', $temp_value);
        $rfname = array_pop($split_value);
        $temp_value = $rfname;
        $split_value = explode('.', $rfname);
        $rname = array_shift($split_value);
        $rname_check = $rname.'.json';

        // extract $ref version
        $matches = [];
        preg_match('/([0-9]+)\-([0-9]+)\-([0-9]+)/', $value, $matches);
        if (count($matches) === 0) {
            $rversion = $container_version;
        } else {
            $rversion = $matches[0];
        }

        // mark model as referenced
        $check_name = $rversion.'/'.$rname;
        if (in_array($check_name, $this->unreferenced_models, true)) {
            unset($this->unreferenced_models[$check_name]);
        }

        // see if the current $ref even exists!
        $cpath_check = PapiMethods::modelFilePath($pdir, $rname_check, $rversion);
        if (!file_exists($cpath_check)) {
            $error = 'File Path: '.$file;
            $error = $error."\nReference Path: ".$key;
            $error = $error."\nCurrent Value: ".$rversion.'/'.$rfname." (DNE!)\n\n";
            $errors[] = $error;

            return $errors;
        }

        // other versions where model might live...
        $versions_to_check = array_filter($valid_versions, function ($version) use ($rversion) {
            return version_compare($version, $rversion) > 0;
        });

        // see if there is a newer $ref...
        foreach ($versions_to_check as $version_to_check) {
            $rpath_check = PapiMethods::modelFilePath($pdir, $rname_check, $version_to_check);

            if (file_exists($rpath_check)) {
                $error = 'File Path: '.$file;
                $error = $error."\nReference Path: ".$key;
                $error = $error."\nCurrent Value: ".$rversion.'/'.$rfname;
                $error = $error."\nRecommended Value: ".$version_to_check.'/'.$rname_check."\n\n";
                $errors[] = $error;
                break;
            }
        }

        return $errors;
    }

    public function checkModelRefs($pdir, $api, $version)
    {
        $errors = [];
        $valid_versions = PapiMethods::versionsEqualToOrBelow($pdir, $api, $version);

        // for each model file...
        foreach (PapiMethods::modelFiles($pdir, $version) as $model_file_name) {
            $mpath = PapiMethods::modelFilePath($pdir, $model_file_name, $version);
            $mjson = PapiMethods::readJsonFromFile($mpath);
            $mversion = basename(dirname($mpath));

            // for each $ref...
            foreach (PapiMethods::arrayFindRecursive($mjson, '$ref') as $result) {
                $ref_errors = $this->checkRef($pdir, $mpath, $mversion, $valid_versions, $result['path'], $result['value']);
                $errors = array_merge($errors, $ref_errors);
            }
        }

        return $errors;
    }

    public function checkSpecRefs($pdir, $api, $version)
    {
        $errors = [];
        $valid_versions = PapiMethods::versionsEqualToOrBelow($pdir, $api, $version);

        $spec_file_path = PapiMethods::specFile($pdir, $api, $version);
        $json = PapiMethods::readJsonFromFile($spec_file_path);

        if ($json) {
            // for each $ref...
            foreach (PapiMethods::arrayFindRecursive($json, '$ref') as $result) {
                $ref_errors = $this->checkRef($pdir, $spec_file_path, '', $valid_versions, $result['path'], $result['value']);
                $errors = array_merge($errors, $ref_errors);
            }
        } else {
            $error = "**File DNE!**\n\n";
            $error = $error.'Path: '.PapiMethods::specsDirectory($pdir, $api).'/'.$api.'.'.$version.'.json';
            $error = $error."\n\n";
            $errors[] = $error;
        }

        return $errors;
    }
}
