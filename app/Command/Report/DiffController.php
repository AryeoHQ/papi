<?php

namespace App\Command\Report;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class DiffController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'report the differences between two versions of an API';
        $this->parameters = [
            ['format', 'spec format, defaults to JSON (JSON|YAML)', 'JSON', false],
            ['s_dir', 'spec directory', '/examples/reference/PetStore', true],
            ['s_prefix', 'spec prefix', 'PetStore (e.g. PetStore.2021-07-23.json)', true],
            ['m_dir', 'models directory', '/examples/models', true],
            ['old_version', 'spec version that comes before new_version', '2021-07-23', true],
            ['new_version', 'spec version that comes after old_version', '2021-07-24', true]
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $spec_dir = $this->getParam('s_dir');
            $spec_prefix = $this->getParam('s_prefix');
            $models_dir = $this->getParam('m_dir');
            $old_version = $this->getParam('old_version');
            $new_version = $this->getParam('new_version');
            $this->compareVersions($spec_dir, $spec_prefix, $models_dir, $old_version, $new_version);
        } else {
            $this->printCommandHelp();
        }
    }

    public function compareVersions($spec_dir, $spec_prefix, $models_dir, $old_version, $new_version)
    {
        if (version_compare($old_version, $new_version) >= 0) {
            $this->getPrinter()->out('error: cannot old_version must be less than new_version', 'error');
            $this->getPrinter()->newline();

            return;
        } else {
            $this->compareOperations($spec_dir, $spec_prefix, $old_version, $new_version);
            $this->compareModels($spec_dir, $models_dir, $old_version, $new_version);
        }
    }

    public function compareOperations($spec_dir, $spec_prefix, $old_version, $new_version)
    {
        // gather operations in specs less than or equal to $old_version
        $older_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $old_version, $this->getFormat());
        $older_operations = $this->gatherOperationsForVersions($spec_dir, $spec_prefix, $older_versions);
        
        // gather operations in specs newer than $old_version, but less than $new_version
        $newer_versions = PapiMethods::versionsBetween($spec_dir, $old_version, false, $new_version, true, $this->getFormat());
        $newer_operations = $this->gatherOperationsForVersions($spec_dir, $spec_prefix, $newer_versions);
        
        $this->showDiff('ROUTES', $older_operations, $newer_operations);
    }

    public function gatherOperationsForVersions($spec_dir, $spec_name, $versions)
    {
        $operations = [];

        $format = $this->getFormat();
        $valid_extensions = PapiMethods::validExtensions($format);

        foreach ($versions as $version) {
            $spec_file_path = '';

            foreach ($valid_extensions as $extension) {
                $spec_file_path = $spec_dir . DIRECTORY_SEPARATOR . $spec_name . '.' . $version . '.' . $extension;
                if (PapiMethods::validFilePath($spec_file_path)) {
                    break;
                }
            }

            $v_operations = PapiMethods::operationsKeys($spec_file_path);
            $operations = array_merge($v_operations, $operations);
        }

        return $operations;
    }

    public function compareModels($spec_dir, $models_dir, $old_version, $new_version)
    {
        // gather models in specs less than or equal to $old_version
        $older_versions = PapiMethods::versionsEqualToOrBelow($spec_dir, $old_version, $this->getFormat());
        $older_models = $this->gatherModelsForVersions($models_dir, $older_versions);

        // gather models in specs newer than $old_version, but less than $new_version
        $newer_versions = PapiMethods::versionsBetween($spec_dir, $old_version, false, $new_version, true, $this->getFormat());
        $newer_models = $this->gatherModelsForVersions($models_dir, $newer_versions);

        $this->showDiff('MODELS', $older_models, $newer_models);
    }

    public function gatherModelsForVersions($models_dir, $versions)
    {
        $models = [];
        foreach ($versions as $version) {
            $v_models = PapiMethods::specFilesInDir($models_dir . DIRECTORY_SEPARATOR . $version, $this->getFormat());
            $models = array_merge($v_models, $models);
        }

        return $models;
    }

    public function showDiff($section_title, $old, $new)
    {
        $items_new = array_filter($new, function ($item) use ($old) {
            return !in_array($item, $old, true);
        });
        $items_updated = array_filter($new, function ($item) use ($old) {
            return in_array($item, $old, true);
        });

        usort($items_new, ["App\Methods\PapiMethods", 'sortItemsInDiff']);
        usort($items_updated, ["App\Methods\PapiMethods", 'sortItemsInDiff']);

        $list_new = array_map(function ($item) {
            return '- '.$item;
        }, $items_new);
        $list_updated = array_map(function ($item) {
            return '- '.$item;
        }, $items_updated);

        // print comparison
        if (count($items_new) || count($items_updated)) {
            $this->getPrinter()->out('# '.$section_title, 'bold');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            if (count($items_new)) {
                $this->getPrinter()->out('**NEW**', 'success');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
                $this->getPrinter()->out(join("\n", $list_new), 'success');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
            }
            if (count($items_updated)) {
                $this->getPrinter()->out('**UPDATED**', 'info');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
                $this->getPrinter()->out(join("\n", $list_updated), 'info');
                $this->getPrinter()->newline();
                $this->getPrinter()->newline();
            }
        }
    }
}
