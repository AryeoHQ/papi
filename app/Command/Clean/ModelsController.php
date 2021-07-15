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
            ['mdir', 'models directory', '/Users/jdoe/Desktop/spec/models'],
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
            $mdir = $this->getParam('mdir');
            $this->cleanModels($mdir);
        } else {
            $this->printCommandHelp();
        }
    }

    public function cleanModels($mdir)
    {
        foreach (PapiMethods::jsonFilesInDir($mdir) as $model_file_name) {
            $mpath = $mdir . '/' . $model_file_name;
            $mjson = $this->cleanModel($mpath);
            PapiMethods::writeJsonToFile($mjson, $mpath);
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
