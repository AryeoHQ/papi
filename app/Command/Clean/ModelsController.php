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
        $this->flags = [
            ['oas_3_1_plus', 'clean models for inclusion in OAS specs that are v3.1+'],
            ['override', 'apply known defaults if key/value pairs already exist']
        ];
        $this->notes = [
            'Cleaning models applies known defaults for missing key/value pairs.',
            'For example, adding `nullable|x-nullable` if not previously defined based on ',
            'a model\'s required properties.',
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
        $override = $this->hasFlag('override');

        if ($array['properties']) {
            $required_properties = [];

            if (isset($array['required'])) {
                $required_properties = $array['required'];
            }

            if (!$this->hasFlag('oas_3_1_plus')) {
                $types_to_check = ['string', 'number', 'integer', 'boolean', 'array'];
                foreach ($array['properties'] as $property_name => $property) {
                    if (isset($property['type'])) {
                        if (in_array($property['type'], $types_to_check, true)) {
                            if (!isset($property['nullable']) || $override) {
                                $property['nullable'] = !in_array($property_name, $required_properties, true);
                            }
                            if (!isset($property['x-nullable']) || $override) {
                                $property['x-nullable'] = !in_array($property_name, $required_properties, true);
                            }
                        }
                    }
                    if (isset($property['$ref'])) {
                        if (!isset($property['nullable']) || $override) {
                            $property['nullable'] = !in_array($property_name, $required_properties, true);
                        }
                        if (!isset($property['x-nullable']) || $override) {
                            $property['x-nullable'] = !in_array($property_name, $required_properties, true);
                        }
                    }
                    $array['properties'][$property_name] = $property;
                }
            }
        }

        return $array;
    }
}
