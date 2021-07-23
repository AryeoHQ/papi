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
            ['models_dir', 'models directory', '/examples/models'],
        ];
        $this->notes = [
            'Cleaning models applies known defaults to models that',
            'have not been previously set or provided. For example, ',
            'marking all non-required properties as `nullable`.',
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $models_dir = $this->getParam('models_dir');
            $this->cleanModels($models_dir);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanModels($models_dir)
    {
        foreach (PapiMethods::jsonFilesInDir($models_dir) as $model_file_name) {
            $model_path = $models_dir . DIRECTORY_SEPARATOR . $model_file_name;
            $model_json = $this->cleanModel($model_path);
            PapiMethods::writeJsonToFile($model_json, $model_path);
        }
    }

    public function cleanModel($model_file_path)
    {
        $json = PapiMethods::readJsonFromFile($model_file_path);

        if ($json['properties']) {
            $required_properties = [];

            if ($json['required']) {
                $required_properties = $json['required'];
            }

            $types_to_check = ['string', 'number', 'integer', 'boolean', 'array'];
            foreach ($json['properties'] as $property_name => $property) {
                if (in_array($property['type'], $types_to_check, true)) {
                    $property['nullable'] = !in_array($property_name, $required_properties, true);
                }
                if (isset($property['$ref'])) {
                    $property['nullable'] = !in_array($property_name, $required_properties, true);
                }
                $json['properties'][$property_name] = $property;
            }
        }

        return $json;
    }
}
