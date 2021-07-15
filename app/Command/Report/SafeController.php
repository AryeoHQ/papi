<?php

namespace App\Command\Report;

use App\Command\PapiController;
use App\Methods\PapiMethods;
use App\Models\SectionResults;
use Minicli\App;

class SafeController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'check if changes to an api are safe';
        $this->parameters = [
            ['lspec', 'path to last spec reference', '/tmp/Aryeo.LAST.json'],
            ['cspec', 'path to current spec reference', '/tmp/Aryeo.CURRENT.json'],
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $cspec = $this->getParam('cspec');
            $lspec = $this->getParam('lspec');
            $this->checkSpecs($cspec, $lspec);
        } else {
            $this->printCommandHelp();
        }
    }

    public function checkSpecs($current_spec_path, $last_spec_path)
    {
        $ljson = PapiMethods::readJsonFromFile($last_spec_path);

        if ($ljson === false) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open last spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        $cjson = PapiMethods::readJsonFromFile($current_spec_path);

        if ($ljson === false) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open current spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        $ljson_version = $ljson['info']['version'];
        $cjson_version = $cjson['info']['version'];

        if ($ljson_version !== $cjson_version) {
            $this->safetyHeader();
            $this->getPrinter()->rawOutput('Specs being compared are not the same version. Exiting quietly.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(0);
        }

        $section_results = [];

        // additions...
        $section_results[] = $this->checkRouteSecurityAdditions($ljson, $cjson);

        // removals...
        $section_results[] = $this->checkResponsePropertyRemovals($ljson, $cjson);
        $section_results[] = $this->checkRequestBodyPropertyRemovals($ljson, $cjson);
        $section_results[] = $this->checkRouteMethodDirectRemovals($ljson, $cjson);
        $section_results[] = $this->checkRouteMethodDeprecatedRemovals($ljson, $cjson);
        $section_results[] = $this->checkRouteMethodInternalRemovals($ljson, $cjson);
        $section_results[] = $this->checkResponseRemovals($ljson, $cjson);
        $section_results[] = $this->checkQueryParamRemovals($ljson, $cjson);
        $section_results[] = $this->checkHeaderRemovals($ljson, $cjson);

        // type changes...
        $section_results[] = $this->checkResponsePropertyTypeChanged($ljson, $cjson);
        $section_results[] = $this->checkRequestBodyPropertyTypeChanged($ljson, $cjson);
        $section_results[] = $this->checkQueryParameterTypeChanged($ljson, $cjson);
        $section_results[] = $this->checkHeaderTypeChanged($ljson, $cjson);
        $section_results[] = $this->checkEnumsChanged($ljson, $cjson);

        // optionality...
        $section_results[] = $this->checkRequestBodyPropertyNowRequired($ljson, $cjson);
        $section_results[] = $this->checkQueryParameterNowRequired($ljson, $cjson);
        $section_results[] = $this->checkHeaderNowRequired($ljson, $cjson);

        // display results and exit accordingly...
        if ($this->displaySectionResultsWithErrors($section_results)) {
            $this->getPrinter()->out('👎 FAIL: Unsafe API changes. Create a new API version!', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        } else {
            $this->getPrinter()->out('👍 PASS: All changes are safe!', 'success');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(0);
        }
    }

    /*
     * Additions
     */

    public function checkRouteSecurityAdditions($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);
            $croute = PapiMethods::getNestedValue($cjson, $route_key);

            // is the security the same?
            $lroute_security = [];
            if (isset($lroute['security'])) {
                $lroute_security = $lroute['security'];
            }
            $croute_security = [];
            if (isset($croute['security'])) {
                $croute_security = $croute['security'];
            }

            $diff = array_diff_assoc($croute_security, $lroute_security);

            if (count($diff) > 0) {
                $new_schemes = array_filter(array_values(PapiMethods::arrayKeysRecursive($diff)), 'is_string');
                $errors[] = sprintf(
                    '%s: `%s` security has been added to this route.',
                    PapiMethods::formatRouteKey($route_key),
                    join(', ', $new_schemes)
                );
            }
        }

        return new SectionResults('Route Security Additions', $errors);
    }

    /*
     * Removals
     */

    public function checkResponsePropertyRemovals($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);

            // for each response...
            if (isset($lroute['responses'])) {
                foreach ($lroute['responses'] as $status_code => $lroute_response) {
                    $croute_response = PapiMethods::getNestedValue($cjson, $route_key.'[responses]'.'['.$status_code.']');

                    // does the current spec have a response for this status code?
                    if ($croute_response) {
                        // are the properties the same?
                        $error = $this->schemaDiff(
                            $lroute_response['content']['application/json']['schema'],
                            $croute_response['content']['application/json']['schema'],
                            PapiMethods::formatRouteKey($route_key),
                            $status_code
                        );

                        if ($error) {
                            $errors[] = $error;
                        }
                    }
                }
            }
        }

        return new SectionResults('Response Property Removals', $errors);
    }

    public function checkRequestBodyPropertyRemovals($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute_request_body = PapiMethods::getNestedValue($ljson, $route_key.'[requestBody]');

            // was there a request body in last spec?
            if ($lroute_request_body) {
                $croute_request_body = PapiMethods::getNestedValue($cjson, $route_key.'[requestBody]');

                // if so, has it changed?
                $error = $this->schemaDiff(
                    $lroute_request_body['content']['application/json']['schema'],
                    $croute_request_body['content']['application/json']['schema'],
                    PapiMethods::formatRouteKey($route_key),
                    'Request Body'
                );

                if ($error) {
                    $errors[] = $error;
                }
            }
        }

        return new SectionResults('Request Body Property Removals', $errors);
    }

    public function checkRouteMethodDirectRemovals($ljson, $cjson)
    {
        $errors = [];

        $lroute_keys = array_keys(PapiMethods::routesFromJson($ljson, true));
        $croute_keys = array_keys(PapiMethods::routesFromJson($cjson, true));

        $diff = array_diff($lroute_keys, $croute_keys);

        if (count($diff) > 0) {
            foreach ($diff as $route_key) {
                $errors[] = sprintf('%s: This route has been removed.', PapiMethods::formatRouteKey($route_key));
            }
        }

        return new SectionResults('Route Removals', $errors);
    }

    public function checkRouteMethodDeprecatedRemovals($ljson, $cjson)
    {
        $errors = $this->routeDiff($ljson, $cjson, 'deprecated', 'deprecated');

        return new SectionResults('Routes Marked Deprecated', $errors);
    }

    public function checkRouteMethodInternalRemovals($ljson, $cjson)
    {
        $errors = $this->routeDiff($ljson, $cjson, 'x-internal', 'internal');

        return new SectionResults('Routes Marked Internal', $errors);
    }

    public function checkResponseRemovals($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute_responses = PapiMethods::getNestedValue($ljson, $route_key.'[responses]');
            $lroute_codes = array_keys($lroute_responses);

            $croute_responses = PapiMethods::getNestedValue($cjson, $route_key.'[responses]');
            $croute_codes = array_keys($croute_responses);

            $diff = array_diff($lroute_codes, $croute_codes);

            if (count($diff) > 0) {
                foreach ($diff as $status_code) {
                    $errors[] = sprintf(
                        '%s (%s): This response has been removed.',
                        PapiMethods::formatRouteKey($route_key),
                        $status_code,
                    );
                }
            }
        }

        return new SectionResults('Response Removals', $errors);
    }

    public function checkQueryParamRemovals($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            // do the query parameters match?
            $error = $this->routeParametersDiff($ljson, $cjson, $route_key, 'query');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Query Parameter Removals', $errors);
    }

    public function checkHeaderRemovals($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            // do the headers match?
            $error = $this->routeParametersDiff($ljson, $cjson, $route_key, 'header');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Header Removals', $errors);
    }

    /*
     * Type Changes
     */

    public function checkResponsePropertyTypeChanged($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);

            // for each response...
            foreach ($lroute['responses'] as $status_code => $lroute_response) {
                $croute_response = PapiMethods::getNestedValue($cjson, $route_key.'[responses]'.'['.$status_code.']');

                // does the current spec have a response for this status code?
                if ($croute_response) {
                    // are the types the same?
                    $diff_errors = $this->schemaPropetryTypeDiff(
                        $lroute_response['content']['application/json']['schema'],
                        $croute_response['content']['application/json']['schema'],
                        PapiMethods::formatRouteKey($route_key),
                        $status_code
                    );

                    $errors = array_merge($errors, $diff_errors);
                }
            }
        }

        return new SectionResults('Response Property Type Changes', $errors);
    }

    public function checkRequestBodyPropertyTypeChanged($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);
            $croute = PapiMethods::getNestedValue($cjson, $route_key);

            // are the request body property types the same?
            $diff_errors = $this->schemaPropetryTypeDiff(
                $lroute['requestBody']['content']['application/json']['schema'],
                $croute['requestBody']['content']['application/json']['schema'],
                PapiMethods::formatRouteKey($route_key),
                'Request Body'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Request Body Property Type Changes', $errors);
    }

    public function checkQueryParameterTypeChanged($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            // are the query parameter types the same?
            $diff_errors = $this->routeParametersTypeDiff(
                $ljson,
                $cjson,
                $route_key,
                'query',
                '[schema][type]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Type Changes', $errors);
    }

    public function checkHeaderTypeChanged($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            // are the header types the same?
            $diff_errors = $this->routeParametersTypeDiff(
                $ljson,
                $cjson,
                $route_key,
                'header',
                '[schema][type]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Header Type Changes', $errors);
    }

    public function checkEnumsChanged($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);
            $croute = PapiMethods::getNestedValue($cjson, $route_key);

            // for each response...
            if (isset($lroute['responses'])) {
                foreach ($lroute['responses'] as $status_code => $lroute_response) {
                    $croute_response = PapiMethods::getNestedValue($cjson, $route_key.'[responses]'.'['.$status_code.']');

                    // for each enum in the response...
                    foreach (PapiMethods::arrayFindRecursive($lroute_response, 'enum') as $result) {
                        $enum_path = $result['path'];
                        $enum_value = $result['value'];

                        // does cjson also have this enum?
                        if ($croute_response) {
                            $cenum_value = PapiMethods::getNestedValue($croute_response, $enum_path);
                            if ($cenum_value) {
                                // has the enum changed?
                                $diff = array_diff($enum_value, $cenum_value);
                                if (count($diff) > 0) {
                                    $errors[] = sprintf(
                                        '%s (%s): Enum mismatch at `%s`.',
                                        PapiMethods::formatRouteKey($route_key),
                                        $status_code,
                                        PapiMethods::formatEnumKey($enum_path),
                                    );
                                }
                            }
                        }
                    }
                }
            }

            // for each route parameter...
            if (isset($lroute['parameters'])) {
                foreach (PapiMethods::arrayFindRecursive($lroute['parameters'], 'enum') as $result) {
                    $enum_path = $result['path'];

                    $trimmed_key = substr($enum_path, 1, -1);
                    $parts = explode('][', $trimmed_key);
                    $parameter_index = $parts[0];
                    $parameter_name = $lroute['parameters'][$parameter_index]['name'];
                    $parameter_in = $lroute['parameters'][$parameter_index]['in'];

                    $enum_value = $result['value'];

                    // does cjson also have this enum?
                    if ($croute) {
                        $cenum_value = PapiMethods::getNestedValue($croute['parameters'], $enum_path);
                        if ($cenum_value) {
                            // has the enum changed?
                            $diff = array_diff($enum_value, $cenum_value);
                            if (count($diff) > 0) {
                                $errors[] = sprintf(
                                    '%s (%s): Enum mismatch for %s.',
                                    PapiMethods::formatRouteKey($route_key),
                                    'parameter::'.$parameter_in,
                                    $parameter_name,
                                );
                            }
                        }
                    }
                }
            }
        }

        return new SectionResults('Check Enums Changed', $errors);
    }

    /*
     * Optionality
     */

    public function checkRequestBodyPropertyNowRequired($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $lroute = PapiMethods::getNestedValue($ljson, $route_key);
            $croute = PapiMethods::getNestedValue($cjson, $route_key);

            $diff_errors = $this->schemaPropetryRequiredDiff(
                $lroute['requestBody']['content']['application/json']['schema'],
                $croute['requestBody']['content']['application/json']['schema'],
                PapiMethods::formatRouteKey($route_key),
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Request Body Property Optionality', $errors);
    }

    public function checkQueryParameterNowRequired($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $diff_errors = $this->routeParametersRequiredDiff(
                $ljson,
                $cjson,
                $route_key,
                'query',
                '[required]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Optionality', $errors);
    }

    public function checkHeaderNowRequired($ljson, $cjson)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($ljson, $cjson) as $route_key) {
            $diff_errors = $this->routeParametersRequiredDiff(
                $ljson,
                $cjson,
                $route_key,
                'header',
                '[required]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Header Optionality', $errors);
    }

    /*
     * Helpers
     */

    public function schemaObjectPropertyMap($schema, $key = 'root', $map = [])
    {
        if ($schema['type'] === 'object') {
            $property_keys = array_keys($schema['properties']);
            $map[$key] = $property_keys;
            foreach ($property_keys as $property_key) {
                $map = array_merge($map, $this->schemaObjectPropertyMap($schema['properties'][$property_key], $key.'.'.$property_key, $map));
            }

            return $map;
        } elseif ($schema['type'] === 'array') {
            return array_merge($map, $this->schemaObjectPropertyMap($schema['items'], $key.'.array[items]', $map));
        } else {
            return $map;
        }
    }

    public function schemaPropertyTypeMap($schema, $key = 'root', $map = [])
    {
        if ($schema['type'] === 'object') {
            $map[$key] = $schema['title'];

            foreach ($schema['properties'] as $property_key => $property) {
                $map = array_merge($map, $this->schemaPropertyTypeMap($property, $key.'.'.$property_key, $map));
            }

            return $map;
        } elseif ($schema['type'] === 'array') {
            return array_merge($map, $this->schemaPropertyTypeMap($schema['items'], $key.'.array[items]', $map));
        } else {
            $map[$key] = $schema['type'];

            return $map;
        }
    }

    public function schemaPropertyRequiredMap($schema, $key = 'root', $map = [])
    {
        if ($schema['type'] === 'object') {
            $required_keys = $schema['required'] ? $schema['required'] : [];
            $map[$key] = $required_keys;

            foreach ($schema['properties'] as $property_key => $property) {
                $map = array_merge($map, $this->schemaPropertyRequiredMap($property, $key.'.'.$property_key, $map));
            }

            return $map;
        } elseif ($schema['type'] === 'array') {
            return array_merge($map, $this->schemaPropertyRequiredMap($schema['items'], $key.'.array[items]', $map));
        } else {
            return $map;
        }
    }

    public function schemaDiff($a_schema, $b_schema, $subject, $location)
    {
        // recursively capture all schema property keys
        $a_schema_property_map = $this->schemaObjectPropertyMap($a_schema);
        $b_schema_property_map = $this->schemaObjectPropertyMap($b_schema);

        // are the property keys the same?
        foreach ($a_schema_property_map as $a_object_id_key => $a_object_keys) {
            if ($b_schema_property_map[$a_object_id_key]) {
                $diff = array_diff($a_object_keys, $b_schema_property_map[$a_object_id_key]);

                if (count($diff) > 0) {
                    return sprintf(
                        '%s (%s): `%s` has been removed from `%s`.',
                        $subject,
                        $location,
                        join(', ', $diff),
                        $a_object_id_key
                    );
                }
            }
        }
    }

    public function schemaPropetryTypeDiff($a_schema, $b_schema, $subject, $location)
    {
        $errors = [];

        // recursively capture all schema property types
        $a_schema_property_type_map = $this->schemaPropertyTypeMap($a_schema);
        $b_schema_property_type_map = $this->schemaPropertyTypeMap($b_schema);

        // are the property types the same?
        foreach ($a_schema_property_type_map as $a_property_path => $a_property_type) {
            if ($b_schema_property_type_map[$a_property_path]) {
                $b_property_type = $b_schema_property_type_map[$a_property_path];

                if (strcmp($a_property_type, $b_property_type) !== 0) {
                    $errors[] = sprintf(
                        '%s (%s): Type mismatch (`%s`|`%s`) for `%s`.',
                        $subject,
                        $location,
                        $a_property_type,
                        $b_property_type,
                        $a_property_path
                    );
                }
            }
        }

        return $errors;
    }

    public function schemaPropetryRequiredDiff($a_schema, $b_schema, $subject)
    {
        $errors = [];

        // recursively capture all objects and their required properties...
        $a_schema_property_required_map = $this->schemaPropertyRequiredMap($a_schema);
        $b_schema_property_required_map = $this->schemaPropertyRequiredMap($b_schema);

        // all required properties the same?
        foreach ($a_schema_property_required_map as $a_object_path => $a_object_properties_required) {
            if ($b_schema_property_required_map[$a_object_path]) {
                $b_object_properties_required = $b_schema_property_required_map[$a_object_path];

                $diff = array_diff($b_object_properties_required, $a_object_properties_required);

                if (count($diff) > 0) {
                    $errors[] = sprintf(
                        '%s: New required parameters `%s` at `%s`.',
                        $subject,
                        join(', ', $diff),
                        $a_object_path
                    );
                }
            }
        }

        return $errors;
    }

    public function routeParametersDiff($a_json, $b_json, $route_key, $parameter_type)
    {
        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_query_parameters = array_values(
            $this->routeParameters($a_json, $route_key, $parameter_type, '[name]')
        );
        $b_route_query_parameters = array_values(
            $this->routeParameters($b_json, $route_key, $parameter_type, '[name]')
        );
        $diff = array_diff($a_route_query_parameters, $b_route_query_parameters);

        if (count($diff) > 0) {
            return sprintf(
                '%s: `%s` has been removed.',
                PapiMethods::formatRouteKey($route_key),
                join(', ', $diff)
            );
        }
    }

    public function routeParametersTypeDiff($a_json, $b_json, $route_key, $param_in, $param_value_key)
    {
        $errors = [];

        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_params = $this->routeParameters($a_json, $route_key, $param_in, $param_value_key);
        $b_route_params = $this->routeParameters($b_json, $route_key, $param_in, $param_value_key);

        // for each param...
        foreach ($a_route_params as $a_route_param_key => $a_route_param_value) {
            $b_route_param_value = $b_route_params[$a_route_param_key];

            if ($b_route_param_value) {
                if (strcmp($a_route_param_value, $b_route_param_value) !== 0) {
                    $errors[] = sprintf(
                        '%s: Type mismatch (`%s`|`%s`) for `%s`.',
                        PapiMethods::formatRouteKey($route_key),
                        $a_route_param_value,
                        $b_route_param_value,
                        $a_route_param_key,
                    );
                }
            }
        }

        return $errors;
    }

    public function routeParametersRequiredDiff($a_json, $b_json, $route_key, $param_in, $param_value_key)
    {
        $errors = [];

        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_params = $this->routeParameters($a_json, $route_key, $param_in, $param_value_key);
        $b_route_params = $this->routeParameters($b_json, $route_key, $param_in, $param_value_key);

        // for each param...
        foreach ($a_route_params as $a_route_param_key => $a_route_param_value) {
            $b_route_param_value = $b_route_params[$a_route_param_key];

            if ($b_route_param_value) {
                if (!$a_route_param_value && $b_route_param_value) {
                    $errors[] = sprintf(
                        '%s: New required parameter `%s`.',
                        PapiMethods::formatRouteKey($route_key),
                        $a_route_param_key,
                    );
                }
            }
        }

        return $errors;
    }

    public function routeParameters($json, $route_key, $parameter_type, $parameter_value_key)
    {
        $route_parameters = [];

        $route_all_parameters = PapiMethods::getNestedValue($json, $route_key.'[parameters]');
        if ($route_all_parameters) {
            $route_matching_parameters = array_filter($route_all_parameters, function ($route_parameter) use ($parameter_type) {
                return $route_parameter['in'] === $parameter_type;
            });

            foreach ($route_matching_parameters as $parameter) {
                $route_parameters[$parameter['name']] = PapiMethods::getNestedValue($parameter, $parameter_value_key);
            }
        }

        return $route_parameters;
    }

    public function routeDiff($a_json, $b_json, $on_property, $subject)
    {
        $errors = [];

        $matching_keys = iterator_to_array(PapiMethods::matchingRouteKeys($a_json, $b_json));

        $a_routes = array_filter($matching_keys, function ($route_key) use ($a_json, $on_property) {
            $property_value = PapiMethods::getNestedValue($a_json, $route_key.'['.$on_property.']');

            return $property_value !== true;
        });

        $b_routes = array_filter($matching_keys, function ($route_key) use ($b_json, $on_property) {
            $property_value = PapiMethods::getNestedValue($b_json, $route_key.'['.$on_property.']');

            return $property_value !== true;
        });

        $diff = array_diff($a_routes, $b_routes);

        if (count($diff) > 0) {
            foreach ($diff as $route_key) {
                $errors[] = sprintf(
                    '%s: This route has been marked as %s.',
                    PapiMethods::formatRouteKey($route_key),
                    $subject
                );
            }
        }

        return $errors;
    }
}
