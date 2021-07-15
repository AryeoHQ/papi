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
        $this->arguments = [
            ['api', 'name of the api', 'Aryeo'],
            ['old_version', 'spec version that comes before new_version', '2021-06-17'],
            ['new_version', 'spec version that comes after old_version', '2021-07-09'],
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
            $old_version = $args[1];
            $new_version = $args[2];
            $pdir = $this->getParam('pdir');
            $this->compareVersions($pdir, $api, $old_version, $new_version);
        } else {
            $this->printCommandHelp();
        }
    }

    public function compareVersions($pdir, $api, $old_version, $new_version)
    {
        if (version_compare($old_version, $new_version) >= 0) {
            $this->getPrinter()->out('error: cannot old_version must be less than new_version', 'error');
            $this->getPrinter()->newline();

            return;
        } else {
            $this->compareRoutes($pdir, $api, $old_version, $new_version);
            $this->compareModels($pdir, $api, $old_version, $new_version);
        }
    }

    public function compareRoutes($pdir, $api, $old_version, $new_version)
    {
        // gather routes in specs less than or equal to $old_version
        $older_versions = PapiMethods::versionsEqualToOrBelow($pdir, $api, $old_version);
        $older_routes = $this->gatherRoutesForVersions($pdir, $api, $older_versions);

        // gather routes in specs newer than $old_version
        $newer_versions = PapiMethods::versionsAbove($pdir, $api, $old_version);
        $newer_routes = $this->gatherRoutesForVersions($pdir, $api, $newer_versions);

        $this->showDiff('ROUTES', $older_routes, $newer_routes);
    }

    public function gatherRoutesForVersions($pdir, $api, $versions)
    {
        $routes = [];
        foreach ($versions as $version) {
            $vroutes = PapiMethods::routes($pdir, $api, $version);
            $routes = array_merge($vroutes, $routes);
        }

        return $routes;
    }

    public function compareModels($pdir, $api, $old_version, $new_version)
    {
        // gather models in specs less than or equal to $old_version
        $older_versions = PapiMethods::versionsEqualToOrBelow($pdir, $api, $old_version);
        $older_models = $this->gatherModelsForVersions($pdir, $older_versions);

        // gather models in specs newer than $old_version
        $newer_versions = PapiMethods::versionsAbove($pdir, $api, $old_version);
        $newer_models = $this->gatherModelsForVersions($pdir, $newer_versions);

        $this->showDiff('MODELS', $older_models, $newer_models);
    }

    public function gatherModelsForVersions($pdir, $versions)
    {
        $models = [];
        foreach ($versions as $version) {
            $vmodels = PapiMethods::models($pdir, $version);
            $models = array_merge($vmodels, $models);
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
