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
            ['l_spec', 'path to last spec reference', '/examples/out/PetStore/PetStore.LAST.json', true],
            ['c_spec', 'path to current spec reference', '/examples/out/PetStore/PetStore.CURRENT.json', true],
        ];
    }

    public function handle()
    {
        $args = array_slice($this->getArgs(), 3);

        if ($this->checkValidInputs($args)) {
            $last_spec = $this->getParam('l_spec');
            $current_spec = $this->getParam('c_spec');
            $this->checkSpecs($current_spec, $last_spec);
        } else {
            $this->printCommandHelp();
        }
    }

    public function checkSpecs($current_spec_path, $last_spec_path)
    {
        $last_array = PapiMethods::readSpecFile($last_spec_path);

        if ($last_array === false) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open last spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        $current_array = PapiMethods::readSpecFile($current_spec_path);

        if ($last_array === false) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open current spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        $last_array_version = '';
        if (isset($last_array['info'])) {
            $last_array_version = $last_array['info']['version'];
        }

        $current_array_version = '';
        if (isset($current_array['info'])) {
            $current_array_version = $current_array['info']['version'];
        }

        if ($last_array_version !== $current_array_version) {
            $this->safetyHeader();
            $this->getPrinter()->rawOutput('Specs being compared are not the same version. Exiting quietly.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(0);
        }

        $section_results = [];

        // additions...
        $section_results[] = $this->checkRouteSecurityAdditions($last_array, $current_array);

        // removals...
        $section_results[] = $this->checkResponsePropertyRemovals($last_array, $current_array);
        $section_results[] = $this->checkRequestBodyPropertyRemovals($last_array, $current_array);
        $section_results[] = $this->checkRouteMethodDirectRemovals($last_array, $current_array);
        $section_results[] = $this->checkRouteMethodDeprecatedRemovals($last_array, $current_array);
        $section_results[] = $this->checkRouteMethodInternalRemovals($last_array, $current_array);
        $section_results[] = $this->checkResponseRemovals($last_array, $current_array);
        $section_results[] = $this->checkQueryParamRemovals($last_array, $current_array);
        $section_results[] = $this->checkHeaderRemovals($last_array, $current_array);

        // type changes...
        $section_results[] = $this->checkResponsePropertyTypeChanged($last_array, $current_array);
        $section_results[] = $this->checkRequestBodyPropertyTypeChanged($last_array, $current_array);
        $section_results[] = $this->checkQueryParameterTypeChanged($last_array, $current_array);
        $section_results[] = $this->checkHeaderTypeChanged($last_array, $current_array);
        $section_results[] = $this->checkEnumsChanged($last_array, $current_array);

        // optionality...
        $section_results[] = $this->checkRequestBodyPropertyNowRequired($last_array, $current_array);
        $section_results[] = $this->checkQueryParameterNowRequired($last_array, $current_array);
        $section_results[] = $this->checkHeaderNowRequired($last_array, $current_array);

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

    public function checkRouteSecurityAdditions($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);
            $current_route = PapiMethods::getNestedValue($current_array, $route_key);

            // is the security the same?
            $last_route_security = [];
            if (isset($last_route['security'])) {
                foreach ($last_route['security'] as $security_object) {
                    $last_route_security[] = array_keys($security_object)[0];
                }
            }
            $current_route_security = [];
            if (isset($current_route['security'])) {
                foreach ($current_route['security'] as $security_object) {
                    $current_route_security[] = array_keys($security_object)[0];
                }
            }

            $diff = array_diff($current_route_security, $last_route_security);

            if (count($diff) > 0) {
                $new_schemes = array_values($diff);
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

    public function checkResponsePropertyRemovals($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);

            // for each response...
            if (isset($last_route['responses'])) {
                foreach ($last_route['responses'] as $status_code => $last_route_response) {
                    $current_route_response = PapiMethods::getNestedValue($current_array, $route_key.'[responses]'.'['.$status_code.']');

                    // does the current spec have a response for this status code?
                    if ($current_route_response) {
                        $last_route_schema = $last_route_response['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $last_route_response['description'], 'properties' => []];
                        $current_route_schema = $current_route_response['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $current_route_response['description'], 'properties' => []];

                        // are the properties the same?
                        $error = $this->schemaDiff(
                            $last_route_schema,
                            $current_route_schema,
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

    public function checkRequestBodyPropertyRemovals($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route_request_body = PapiMethods::getNestedValue($last_array, $route_key.'[requestBody]');

            // was there a request body in last spec?
            if ($last_route_request_body) {
                $current_route_request_body = PapiMethods::getNestedValue($current_array, $route_key.'[requestBody]');

                // if so, has it changed?
                $error = $this->schemaDiff(
                    $last_route_request_body['content']['application/json']['schema'],
                    $current_route_request_body['content']['application/json']['schema'],
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

    public function checkRouteMethodDirectRemovals($last_array, $current_array)
    {
        $errors = [];

        $last_route_keys = array_keys(PapiMethods::routesFromArray($last_array, true));
        $current_route_keys = array_keys(PapiMethods::routesFromArray($current_array, true));

        $diff = array_diff($last_route_keys, $current_route_keys);

        if (count($diff) > 0) {
            foreach ($diff as $route_key) {
                $errors[] = sprintf('%s: This route has been removed.', PapiMethods::formatRouteKey($route_key));
            }
        }

        return new SectionResults('Route Removals', $errors);
    }

    public function checkRouteMethodDeprecatedRemovals($last_array, $current_array)
    {
        $errors = $this->routeDiff($last_array, $current_array, 'deprecated', 'deprecated');

        return new SectionResults('Routes Marked Deprecated', $errors);
    }

    public function checkRouteMethodInternalRemovals($last_array, $current_array)
    {
        $errors = $this->routeDiff($last_array, $current_array, 'x-internal', 'internal');

        return new SectionResults('Routes Marked Internal', $errors);
    }

    public function checkResponseRemovals($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route_responses = PapiMethods::getNestedValue($last_array, $route_key.'[responses]');
            $last_route_codes = array_keys($last_route_responses);

            $current_route_responses = PapiMethods::getNestedValue($current_array, $route_key.'[responses]');
            $current_route_codes = array_keys($current_route_responses);

            $diff = array_diff($last_route_codes, $current_route_codes);

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

    public function checkQueryParamRemovals($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            // do the query parameters match?
            $error = $this->routeParametersDiff($last_array, $current_array, $route_key, 'query');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Query Parameter Removals', $errors);
    }

    public function checkHeaderRemovals($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            // do the headers match?
            $error = $this->routeParametersDiff($last_array, $current_array, $route_key, 'header');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Header Removals', $errors);
    }

    /*
     * Type Changes
     */

    public function checkResponsePropertyTypeChanged($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);

            // for each response...
            foreach ($last_route['responses'] as $status_code => $last_route_response) {
                $current_route_response = PapiMethods::getNestedValue($current_array, $route_key.'[responses]'.'['.$status_code.']');

                // does the current spec have a response for this status code?
                if ($current_route_response) {
                    $last_route_schema = $last_route_response['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $last_route_response['description'], 'properties' => []];
                    $current_route_schema = $current_route_response['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $current_route_response['description'], 'properties' => []];

                    // are the types the same?
                    $diff_errors = $this->schemaPropertyTypeDiff(
                        $last_route_schema,
                        $current_route_schema,
                        PapiMethods::formatRouteKey($route_key),
                        $status_code
                    );

                    $errors = array_merge($errors, $diff_errors);
                }
            }
        }

        return new SectionResults('Response Property Type Changes', $errors);
    }

    public function checkRequestBodyPropertyTypeChanged($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);
            $current_route = PapiMethods::getNestedValue($current_array, $route_key);

            $last_route_schema = $last_route['requestBody']['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $route_key, 'properties' => []];
            $current_route_schema = $current_route['requestBody']['content']['application/json']['schema'] ?? ['type' => 'object', 'title' => $route_key, 'properties' => []];

            // are the request body property types the same?
            $diff_errors = $this->schemaPropertyTypeDiff(
                $last_route_schema,
                $current_route_schema,
                PapiMethods::formatRouteKey($route_key),
                'Request Body'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Request Body Property Type Changes', $errors);
    }

    public function checkQueryParameterTypeChanged($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            // are the query parameter types the same?
            $diff_errors = $this->routeParametersTypeDiff(
                $last_array,
                $current_array,
                $route_key,
                'query',
                '[schema][type]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Type Changes', $errors);
    }

    public function checkHeaderTypeChanged($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            // are the header types the same?
            $diff_errors = $this->routeParametersTypeDiff(
                $last_array,
                $current_array,
                $route_key,
                'header',
                '[schema][type]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Header Type Changes', $errors);
    }

    public function checkEnumsChanged($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);
            $current_route = PapiMethods::getNestedValue($current_array, $route_key);

            // for each response...
            if (isset($last_route['responses'])) {
                foreach ($last_route['responses'] as $status_code => $last_route_response) {
                    $current_route_response = PapiMethods::getNestedValue($current_array, $route_key.'[responses]'.'['.$status_code.']');

                    // for each enum in the response...
                    foreach (PapiMethods::arrayFindRecursive($last_route_response, 'enum') as $result) {
                        $enum_path = $result['path'];
                        $enum_value = $result['value'];

                        // does current_array also have this enum?
                        if ($current_route_response) {
                            $current_enum_value = PapiMethods::getNestedValue($current_route_response, $enum_path);
                            if ($current_enum_value) {
                                // has the enum changed?
                                $diff = array_diff($enum_value, $current_enum_value);
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
            if (isset($last_route['parameters'])) {
                foreach (PapiMethods::arrayFindRecursive($last_route['parameters'], 'enum') as $result) {
                    $enum_path = $result['path'];

                    $trimmed_key = substr($enum_path, 1, -1);
                    $parts = explode('][', $trimmed_key);
                    $parameter_index = $parts[0];
                    $parameter_name = $last_route['parameters'][$parameter_index]['name'];
                    $parameter_in = $last_route['parameters'][$parameter_index]['in'];

                    $enum_value = $result['value'];

                    // does current_array also have this enum?
                    if ($current_route) {
                        $current_enum_value = PapiMethods::getNestedValue($current_route['parameters'], $enum_path);
                        if ($current_enum_value) {
                            // has the enum changed?
                            $diff = array_diff($enum_value, $current_enum_value);
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

    public function checkRequestBodyPropertyNowRequired($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $last_route = PapiMethods::getNestedValue($last_array, $route_key);
            $current_route = PapiMethods::getNestedValue($current_array, $route_key);

            $last_route_schema = $last_route['requestBody']['content']['application/json']['schema'] ?? ['type' => 'blank'];
            $current_route_schema = $current_route['requestBody']['content']['application/json']['schema'] ?? ['type' => 'blank'];

            $diff_errors = $this->schemaPropertyRequiredDiff(
                $last_route_schema,
                $current_route_schema,
                PapiMethods::formatRouteKey($route_key),
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Request Body Property Optionality', $errors);
    }

    public function checkQueryParameterNowRequired($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $diff_errors = $this->routeParametersRequiredDiff(
                $last_array,
                $current_array,
                $route_key,
                'query',
                '[required]'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Optionality', $errors);
    }

    public function checkHeaderNowRequired($last_array, $current_array)
    {
        $errors = [];

        // for matching routes...
        foreach (PapiMethods::matchingRouteKeys($last_array, $current_array) as $route_key) {
            $diff_errors = $this->routeParametersRequiredDiff(
                $last_array,
                $current_array,
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
            $property_keys = array_keys($schema['properties'] ?? []);
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
            $properties = $schema['properties'] ?? [];

            foreach ($properties as $property_key => $property) {
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
            $required_keys = $schema['required'] ?? [];
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

    public function schemaPropertyTypeDiff($a_schema, $b_schema, $subject, $location)
    {
        $errors = [];

        // recursively capture all schema property types
        $a_schema_property_type_map = $this->schemaPropertyTypeMap($a_schema);
        $b_schema_property_type_map = $this->schemaPropertyTypeMap($b_schema);

        // are the property types the same?
        foreach ($a_schema_property_type_map as $a_property_path => $a_property_type) {
            if (isset($b_schema_property_type_map[$a_property_path])) {
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
        }

        return $errors;
    }

    public function schemaPropertyRequiredDiff($a_schema, $b_schema, $subject)
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

    public function routeParametersDiff($a_array, $b_array, $route_key, $parameter_type)
    {
        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_query_parameters = array_values(
            $this->routeParameters($a_array, $route_key, $parameter_type, '[name]')
        );
        $b_route_query_parameters = array_values(
            $this->routeParameters($b_array, $route_key, $parameter_type, '[name]')
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

    public function routeParametersTypeDiff($a_array, $b_array, $route_key, $param_in, $param_value_key)
    {
        $errors = [];

        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_params = $this->routeParameters($a_array, $route_key, $param_in, $param_value_key);
        $b_route_params = $this->routeParameters($b_array, $route_key, $param_in, $param_value_key);

        // for each param...
        foreach ($a_route_params as $a_route_param_key => $a_route_param_value) {
            if (isset($b_route_params[$a_route_param_key])) {
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
        }

        return $errors;
    }

    public function routeParametersRequiredDiff($a_array, $b_array, $route_key, $param_in, $param_value_key)
    {
        $errors = [];

        // routeParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_route_params = $this->routeParameters($a_array, $route_key, $param_in, $param_value_key);
        $b_route_params = $this->routeParameters($b_array, $route_key, $param_in, $param_value_key);

        // for each param...
        foreach ($a_route_params as $a_route_param_key => $a_route_param_value) {
            if (isset($b_route_params[$a_route_param_key])) {
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
        }

        return $errors;
    }

    public function routeParameters($array, $route_key, $parameter_type, $parameter_value_key)
    {
        $route_parameters = [];

        $route_all_parameters = PapiMethods::getNestedValue($array, $route_key.'[parameters]');
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

    public function routeDiff($a_array, $b_array, $on_property, $subject)
    {
        $errors = [];

        $matching_keys = iterator_to_array(PapiMethods::matchingRouteKeys($a_array, $b_array));

        $a_routes = array_filter($matching_keys, function ($route_key) use ($a_array, $on_property) {
            $property_value = PapiMethods::getNestedValue($a_array, $route_key.'['.$on_property.']');

            return $property_value !== true;
        });

        $b_routes = array_filter($matching_keys, function ($route_key) use ($b_array, $on_property) {
            $property_value = PapiMethods::getNestedValue($b_array, $route_key.'['.$on_property.']');

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
