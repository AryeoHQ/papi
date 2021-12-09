<?php

namespace App\Command\Clean;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use Minicli\App;

class ModelsController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'clean models';
        $this->parameters = [
            ['format', 'spec format, defaults to JSON (JSON|YAML)', 'JSON', false],
            ['m_dir', 'models directory', '/examples/models', true],
        ];
        $this->flags = [];
        $this->notes = [
            'Cleaning models applies known defaults for specs.',
            'For example, if `x-nullable` is declared (an old OAS 2 extension) then it ',
            'removed.',
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $models_dir = $this->getParam('m_dir');
            $this->cleanModels($models_dir);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanModels($models_dir)
    {
        $format = $this->getFormat();

        foreach (PapiMethods::specFilesInDir($models_dir, $format) as $model_file_name) {
            $model_path = $models_dir . DIRECTORY_SEPARATOR . $model_file_name;
            $model_array = $this->cleanModel($model_path);
            PapiMethods::writeSpecFile($model_array, $model_path, $format);
        }
    }

    public function cleanModel($model_file_path)
    {
        $array = PapiMethods::readSpecFile($model_file_path);

        if ($array['properties']) {
            $types_to_check = ['string', 'number', 'integer', 'boolean', 'array'];
            foreach ($array['properties'] as $property_name => $property) {
                if (isset($property['type'])) {
                    if (in_array($property['type'], $types_to_check, true)) {
                        if (isset($property['x-nullable'])) {
                            unset($property['x-nullable']);
                        }
                    }
                }
                if (isset($property['$ref'])) {
                    if (isset($property['x-nullable'])) {
                        unset($property['x-nullable']);
                    }
                }
                $array['properties'][$property_name] = $property;
            }
        }

        return $array;
    }
}
