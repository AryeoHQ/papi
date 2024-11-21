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
        // open last api spec
        $last_open_api = PapiMethods::readSpecFileToOpenApi($last_spec_path);
        if ($last_open_api === null) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open last spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        // open current api spec
        $current_open_api = PapiMethods::readSpecFileToOpenApi($current_spec_path);
        if ($current_open_api === null) {
            $this->safetyHeader();
            $this->getPrinter()->out('👎 FAIL: Unable to open current spec.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(-1);
        }

        // ensure we are checking specs with same version
        if ($last_open_api->info->version !== $current_open_api->info->version) {
            $this->safetyHeader();
            $this->getPrinter()->rawOutput('Specs being compared are not the same version. Exiting quietly.', 'error');
            $this->getPrinter()->newline();
            $this->getPrinter()->newline();
            exit(0);
        }

        $section_results = [];

        // additions...
        $section_results[] = $this->checkOperationSecurityAdditions($last_open_api, $current_open_api);

        // removals...
        $section_results[] = $this->checkResponsePropertyRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkRequestBodyPropertyRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkOperationMethodDirectRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkOperationMethodDeprecatedRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkOperationMethodInternalRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkResponseRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkQueryParamRemovals($last_open_api, $current_open_api);
        $section_results[] = $this->checkHeaderRemovals($last_open_api, $current_open_api);

        // type changes...
        $section_results[] = $this->checkResponsePropertyTypeChanged($last_open_api, $current_open_api);
        $section_results[] = $this->checkRequestBodyPropertyTypeChanged($last_open_api, $current_open_api);
        $section_results[] = $this->checkQueryParameterTypeChanged($last_open_api, $current_open_api);
        $section_results[] = $this->checkHeaderTypeChanged($last_open_api, $current_open_api);
        $section_results[] = $this->checkEnumsChanged($last_open_api, $current_open_api);

        // optionality...
        $section_results[] = $this->checkResponsePropertyNowNullable($last_open_api, $current_open_api);
        $section_results[] = $this->checkRequestBodyPropertyNowRequired($last_open_api, $current_open_api);
        $section_results[] = $this->checkQueryParameterNowRequired($last_open_api, $current_open_api);
        $section_results[] = $this->checkHeaderNowRequired($last_open_api, $current_open_api);

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

    public function checkOperationSecurityAdditions($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation = PapiMethods::getOperation($last_open_api, $operation_key);
            $current_operation = PapiMethods::getOperation($current_open_api, $operation_key);

            // is the security the same?
            $last_operation_security = [];
            if ($last_operation->security) {
                foreach ($last_operation->security as $security_object) {
                    foreach ($security_object->getSerializableData() as $security_key => $value) {
                        $last_operation_security[] = $security_key;
                    }
                }
            }

            $current_operation_security = [];
            if ($current_operation->security) {
                foreach ($current_operation->security as $security_object) {
                    foreach ($security_object->getSerializableData() as $security_key => $value) {
                        $current_operation_security[] = $security_key;
                    }
                }
            }

            $diff = array_diff($current_operation_security, $last_operation_security);

            if (count($diff) > 0) {
                $new_schemes = array_values($diff);
                $errors[] = sprintf(
                    '%s: `%s` security has been added to this operation.',
                    PapiMethods::formatOperationKey($operation_key),
                    join(', ', $new_schemes)
                );
            }
        }

        return new SectionResults('Operation Security Additions', $errors);
    }

    /*
     * Removals
     */

    public function checkResponsePropertyRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation = PapiMethods::getOperation($last_open_api, $operation_key);
            $last_responses = isset($last_operation->responses) ?? array();

            // for each response...
            if (is_countable($last_responses)) {
                if (count($last_responses) > 0) {
                    foreach ($last_responses as $status_code => $last_operation_response) {
                        $current_operation_response = PapiMethods::getOperationResponse($current_open_api, $operation_key, $status_code);

                        // does the current spec have a response for this status code?
                        if ($current_operation_response) {
                            // are the properties the same?
                            $error = $this->schemaDiff(
                                PapiMethods::getSchemaArrayFromSpecObject($last_operation_response),
                                PapiMethods::getSchemaArrayFromSpecObject($current_operation_response),
                                PapiMethods::formatOperationKey($operation_key),
                                $status_code
                            );

                            if ($error) {
                                $errors[] = $error;
                            }
                        }
                    }
                }
            }
        }

        return new SectionResults('Response Property Removals', $errors);
    }

    public function checkRequestBodyPropertyRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation_request_body = PapiMethods::getOperationRequestBody($last_open_api, $operation_key);

            // was there a request body in last spec?
            if ($last_operation_request_body) {
                $current_operation_request_body = PapiMethods::getOperationRequestBody($current_open_api, $operation_key);
                // if so, has it changed?
                $error = $this->schemaDiff(
                    PapiMethods::getSchemaArrayFromSpecObject($last_operation_request_body),
                    PapiMethods::getSchemaArrayFromSpecObject($current_operation_request_body),
                    PapiMethods::formatOperationKey($operation_key),
                    'Request Body'
                );

                if ($error) {
                    $errors[] = $error;
                }
            }
        }

        return new SectionResults('Request Body Property Removals', $errors);
    }

    public function checkOperationMethodDirectRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        $last_operation_keys = array_keys(PapiMethods::operationKeysFromOpenApi($last_open_api, true));
        $current_operation_keys = array_keys(PapiMethods::operationKeysFromOpenApi($current_open_api, true));

        $diff = array_diff($last_operation_keys, $current_operation_keys);

        if (count($diff) > 0) {
            foreach ($diff as $operation_key) {
                $errors[] = sprintf('%s: This operation has been removed.', PapiMethods::formatOperationKey($operation_key));
            }
        }

        return new SectionResults('Operation Removals', $errors);
    }

    public function checkOperationMethodDeprecatedRemovals($last_open_api, $current_open_api)
    {
        $errors = $this->operationDiff($last_open_api, $current_open_api, 'deprecated', 'deprecated');

        return new SectionResults('Operations Marked Deprecated', $errors);
    }

    public function checkOperationMethodInternalRemovals($last_open_api, $current_open_api)
    {
        $errors = $this->operationDiff($last_open_api, $current_open_api, 'x-internal', 'internal');

        return new SectionResults('Operations Marked Internal', $errors);
    }

    public function checkResponseRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation_responses = isset(PapiMethods::getOperation($last_open_api, $operation_key)->responses) ?? array();
            $last_operation_codes = array_keys(PapiMethods::objectToArray($last_operation_responses));

            $current_operation_responses = isset(PapiMethods::getOperation($current_open_api, $operation_key)->responses) ?? array();
            $current_operation_codes = array_keys(PapiMethods::objectToArray($current_operation_responses));

            $diff = array_diff($last_operation_codes, $current_operation_codes);

            if (count($diff) > 0) {
                foreach ($diff as $status_code) {
                    $errors[] = sprintf(
                        '%s (%s): This response has been removed.',
                        PapiMethods::formatOperationKey($operation_key),
                        $status_code,
                    );
                }
            }
        }

        return new SectionResults('Response Removals', $errors);
    }

    public function checkQueryParamRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            // do the query parameters match?
            $error = $this->operationParametersDiff($last_open_api, $current_open_api, $operation_key, 'query');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Query Parameter Removals', $errors);
    }

    public function checkHeaderRemovals($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            // do the headers match?
            $error = $this->operationParametersDiff($last_open_api, $current_open_api, $operation_key, 'header');
            if ($error) {
                $errors[] = $error;
            }
        }

        return new SectionResults('Header Removals', $errors);
    }

    /*
     * Type Changes
     */

    public function checkResponsePropertyTypeChanged($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation = PapiMethods::getOperation($last_open_api, $operation_key);
            $last_operation_responses = isset($last_operation->responses) ?? array();

            // for each response...
            foreach ($last_operation_responses as $status_code => $last_operation_response) {
                $current_operation_response = PapiMethods::getOperationResponse($current_open_api, $operation_key, $status_code);

                // does the current spec have a response for this status code?
                if ($current_operation_response) {
                    $last_operation_schema = PapiMethods::getSchemaArrayFromSpecObject($last_operation_response);
                    $current_operation_schema = PapiMethods::getSchemaArrayFromSpecObject($current_operation_response);

                    // are the types the same?
                    $diff_errors = $this->schemaPropertyTypeDiff(
                        $last_operation_schema,
                        $current_operation_schema,
                        PapiMethods::formatOperationKey($operation_key),
                        $status_code
                    );

                    $errors = array_merge($errors, $diff_errors);
                }
            }
        }

        return new SectionResults('Response Property Type Changes', $errors);
    }

    public function checkRequestBodyPropertyTypeChanged($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation_request_body = PapiMethods::getOperationRequestBody($last_open_api, $operation_key);
            $current_operation_request_body = PapiMethods::getOperationRequestBody($current_open_api, $operation_key);

            $last_operation_schema = PapiMethods::getSchemaArrayFromSpecObject($last_operation_request_body);
            $current_operation_schema = PapiMethods::getSchemaArrayFromSpecObject($current_operation_request_body);

            // are the request body property types the same?
            $diff_errors = $this->schemaPropertyTypeDiff(
                $last_operation_schema,
                $current_operation_schema,
                PapiMethods::formatOperationKey($operation_key),
                'Request Body'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Request Body Property Type Changes', $errors);
    }

    public function checkQueryParameterTypeChanged($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            // are the query parameter types the same?
            $diff_errors = $this->operationParametersSchemaDiff(
                $last_open_api,
                $current_open_api,
                $operation_key,
                'query',
                'type'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Type Changes', $errors);
    }

    public function checkHeaderTypeChanged($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            // are the header types the same?
            $diff_errors = $this->operationParametersSchemaDiff(
                $last_open_api,
                $current_open_api,
                $operation_key,
                'header',
                'type'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Header Type Changes', $errors);
    }

    public function checkEnumsChanged($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation = PapiMethods::getOperation($last_open_api, $operation_key);
            $last_operation_responses = isset($last_operation->responses) ?? array();
            $last_operation_parameters = $last_operation->parameters;

            $current_operation = PapiMethods::getOperation($current_open_api, $operation_key);
            $current_operation_parameters = $current_operation->parameters;

            // for each response...
            foreach ($last_operation_responses as $status_code => $last_operation_response) {
                $current_operation_response = PapiMethods::getOperationResponse($current_open_api, $operation_key, $status_code);

                if ($current_operation_response) {
                    $current_operation_response_array = PapiMethods::objectToArray($current_operation_response->getSerializableData());
                    $last_operation_response_array = PapiMethods::objectToArray($last_operation_response->getSerializableData());

                    // for each enum in the response...
                    foreach (PapiMethods::arrayFindRecursive($last_operation_response_array, 'enum') as $result) {
                        $last_enum_path = $result['path'];
                        $last_enum_value = $result['value'];

                        // does current_array also have this enum?
                        if ($current_operation_response) {
                            $current_enum_value = PapiMethods::getNestedValue($current_operation_response_array, $last_enum_path);

                            if ($current_enum_value) {
                                $current_enum_cases = array_values($current_enum_value);
                                $last_enum_cases = array_values($last_enum_value);
                                $intersections = array_intersect($last_enum_cases, $current_enum_cases);

                                // does the new array at least contain all values from the previous (i.e. no removals)
                                if (count($intersections) !== count($last_enum_cases)) {
                                    $difference = join(', ', array_diff($last_enum_cases, $current_enum_cases));
                                    $errors[] = sprintf(
                                        '%s (%s): Enum removal detected at `%s` (%s).',
                                        PapiMethods::formatOperationKey($operation_key),
                                        $status_code,
                                        $last_enum_path,
                                        $difference
                                    );
                                }
                            }
                        }
                    }
                }
            }

            // for each operation parameter...
            foreach ($last_operation_parameters as $parameter_key => $last_operation_parameter) {
                if (isset($current_operation_parameters[$parameter_key])) {
                    $current_operation_parameter = $current_operation_parameters[$parameter_key];

                    $last_operation_parameters_array = PapiMethods::objectToArray($last_operation_parameter->getSerializableData());
                    $current_operation_parameters_array = PapiMethods::objectToArray($current_operation_parameter->getSerializableData());

                    foreach (PapiMethods::arrayFindRecursive($last_operation_parameters_array, 'enum') as $result) {
                        $last_enum_path = $result['path'];

                        $parameter_name = $last_operation_parameter->name;
                        $parameter_in = $last_operation_parameter->in;
                        $last_enum_value = $result['value'];

                        // does current_array also have this enum?
                        if ($current_operation) {
                            $current_enum_value = PapiMethods::getNestedValue($current_operation_parameters_array, $last_enum_path);

                            if ($current_enum_value) {
                                $current_enum_cases = array_values($current_enum_value);
                                $last_enum_cases = array_values($last_enum_value);
                                $intersections = array_intersect($last_enum_cases, $current_enum_cases);

                                // does the new array at least contain all values from the previous (i.e. no removals)
                                if (count($intersections) !== count($last_enum_cases)) {
                                    $difference = join(', ', array_diff($last_enum_cases, $current_enum_cases));
                                    $errors[] = sprintf(
                                        '%s (%s): Enum removal detected at `%s` (%s).',
                                        PapiMethods::formatOperationKey($operation_key),
                                        'parameter::' . $parameter_in,
                                        $parameter_name,
                                        $difference
                                    );
                                }
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

    public function checkResponsePropertyNowNullable($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation = PapiMethods::getOperation($last_open_api, $operation_key);
            $last_operation_responses = isset($last_operation->responses) ?? array();

            // for each response...
            foreach ($last_operation_responses as $status_code => $last_operation_response) {
                $current_operation_response = PapiMethods::getOperationResponse($current_open_api, $operation_key, $status_code);

                if ($current_operation_response) {
                    $current_operation_response_schema = PapiMethods::getSchemaArrayFromSpecObject($current_operation_response);
                    $last_operation_response_schema = PapiMethods::getSchemaArrayFromSpecObject($last_operation_response);

                    if (isset($last_operation_response_schema["properties"]) && isset($current_operation_response_schema["properties"])) {
                        $last_operation_response_properties = $last_operation_response_schema["properties"];
                        $current_operation_response_properties = $current_operation_response_schema["properties"];

                        // for each response property...
                        foreach ($last_operation_response_properties as $property_key => $last_property) {
                            $errors = array_merge(
                                $errors,
                                $this->comparePropertySafeNullabilityRecursive(
                                    $operation_key,
                                    $status_code,
                                    '',
                                    $property_key,
                                    $last_property,
                                    $current_operation_response_properties[$property_key] ?? []
                                )
                            );
                        }
                    }
                }
            }
        }

        return new SectionResults('Response Property Optionality', $errors);
    }

    public function comparePropertySafeNullabilityRecursive($operation_key, $status_code, $property_key_prefix, $property_key, $property_one, $property_two)
    {
        $errors = [];

        $errors = array_merge(
            $errors,
            $this->comparePropertySafeNullability(
                $operation_key,
                $status_code,
                $property_key_prefix,
                $property_key,
                $property_one,
                $property_two
            )
        );

        if (isset($property_one["properties"]) && isset($property_two["properties"])) {
            $property_one_properties = $property_one["properties"];
            $property_two_properties = $property_two["properties"];

            // for each response property...
            foreach ($property_one_properties as $next_property_key => $next_property) {
                if (isset($property_two_properties[$next_property_key])) {
                    $errors = array_merge(
                        $errors,
                        $this->comparePropertySafeNullabilityRecursive(
                            $operation_key,
                            $status_code,
                            $property_key . '.',
                            $next_property_key,
                            $next_property,
                            $property_two_properties[$next_property_key]
                        )
                    );
                }
            }
        }

        return $errors;
    }

    public function comparePropertySafeNullability($operation_key, $status_code, $property_key_prefix, $property_key, $property_one, $property_two)
    {
        $property_one_nullable = $property_one['nullable'] ?? false;
        $property_two_nullable = $property_two['nullable'] ?? false;

        if (!$property_one_nullable && $property_two_nullable) {
            return [sprintf(
                '%s (%s): Property `%s` in the response changed from non-nullable to nullable.',
                PapiMethods::formatOperationKey($operation_key),
                $status_code,
                $property_key_prefix . $property_key
            )];
        } else {
            return [];
        }
    }

    public function checkRequestBodyPropertyNowRequired($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $last_operation_request_body = PapiMethods::getOperationRequestBody($last_open_api, $operation_key);
            $current_operation_request_body = PapiMethods::getOperationRequestBody($current_open_api, $operation_key);

            // does the current spec have a response for this status code?
            if ($current_operation_request_body) {
                $last_operation_request_body_schema = PapiMethods::getSchemaArrayFromSpecObject($last_operation_request_body);
                $current_operation_request_body_schema = PapiMethods::getSchemaArrayFromSpecObject($current_operation_request_body);

                $diff_errors = $this->schemaPropertyRequiredDiff(
                    $last_operation_request_body_schema,
                    $current_operation_request_body_schema,
                    PapiMethods::formatOperationKey($operation_key),
                );

                $errors = array_merge($errors, $diff_errors);
            }
        }

        return new SectionResults('Request Body Property Optionality', $errors);
    }

    public function checkQueryParameterNowRequired($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $diff_errors = $this->operationParametersRequiredDiff(
                $last_open_api,
                $current_open_api,
                $operation_key,
                'query',
                'required'
            );

            $errors = array_merge($errors, $diff_errors);
        }

        return new SectionResults('Query Parameter Optionality', $errors);
    }

    public function checkHeaderNowRequired($last_open_api, $current_open_api)
    {
        $errors = [];

        // for matching operations...
        foreach (PapiMethods::matchingOperationKeys($last_open_api, $current_open_api) as $operation_key) {
            $diff_errors = $this->operationParametersRequiredDiff(
                $last_open_api,
                $current_open_api,
                $operation_key,
                'header',
                'required'
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
        if (isset($schema['type'])) {
            if ($schema['type'] === 'object') {
                $property_keys = array_keys($schema['properties'] ?? []);
                $map[$key] = $property_keys;
                foreach ($property_keys as $property_key) {
                    $map = array_merge($map, $this->schemaObjectPropertyMap($schema['properties'][$property_key], $key . '.' . $property_key, $map));
                }

                return $map;
            } elseif ($schema['type'] === 'array') {
                return array_merge($map, $this->schemaObjectPropertyMap($schema['items'], $key . '.array[items]', $map));
            } else {
                return $map;
            }
        } else {
            return $map;
        }
    }

    public function schemaPropertyTypeMap($schema, $key = 'root', $map = [])
    {
        if (isset($schema['type'])) {
            if ($schema['type'] === 'object') {
                $map[$key . '.title'] = $schema['title'] ?? '';
                $properties = $schema['properties'] ?? [];

                foreach ($properties as $property_key => $property) {
                    $map = array_merge($map, $this->schemaPropertyTypeMap($property, $key . '.' . $property_key, $map));
                }

                return $map;
            } elseif ($schema['type'] === 'array') {
                return array_merge($map, $this->schemaPropertyTypeMap($schema['items'], $key . '.array[items]', $map));
            } else {
                $map[$key] = $schema['type'];

                return $map;
            }
        } else {
            return $map;
        }
    }

    public function schemaPropertyRequiredMap($schema, $key = 'root', $map = [])
    {
        if (isset($schema['type'])) {
            if ($schema['type'] === 'object') {
                $required_keys = $schema['required'] ?? [];
                $map[$key] = $required_keys;

                if (isset($schema['properties'])) {
                    foreach ($schema['properties'] as $property_key => $property) {
                        $map = array_merge($map, $this->schemaPropertyRequiredMap($property, $key . '.' . $property_key, $map));
                    }
                }

                return $map;
            } elseif ($schema['type'] === 'array') {
                return array_merge($map, $this->schemaPropertyRequiredMap($schema['items'], $key . '.array[items]', $map));
            } else {
                return $map;
            }
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
            if (isset($b_schema_property_map[$a_object_id_key])) {
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
        foreach ($a_schema_property_type_map as $a_property_path => $a_property_value) {
            if (isset($b_schema_property_type_map[$a_property_path])) {
                if ($b_schema_property_type_map[$a_property_path]) {
                    $b_property_value = $b_schema_property_type_map[$a_property_path];

                    if (strcmp($a_property_value, $b_property_value) !== 0 && $a_property_path !== 'root.title') {
                        $errors[] = sprintf(
                            '%s (%s): Type mismatch (`%s`|`%s`) for `%s`.',
                            $subject,
                            $location,
                            $a_property_value,
                            $b_property_value,
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

    public function operationParametersDiff($a_open_api, $b_open_api, $operation_key, $parameter_type)
    {
        // operationParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_operation_query_parameters = array_keys(
            $this->operationParameters($a_open_api, $operation_key, $parameter_type, '[name]')
        );
        $b_operation_query_parameters = array_keys(
            $this->operationParameters($b_open_api, $operation_key, $parameter_type, '[name]')
        );
        $diff = array_diff($a_operation_query_parameters, $b_operation_query_parameters);

        if (count($diff) > 0) {
            return sprintf(
                '%s: `%s` has been removed.',
                PapiMethods::formatOperationKey($operation_key),
                join(', ', $diff)
            );
        }
    }

    public function operationParametersSchemaDiff($a_open_api, $b_open_api, $operation_key, $param_in, $schema_value_key)
    {
        $errors = [];

        // operationParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_operation_params = $this->operationSchemaParameters($a_open_api, $operation_key, $param_in, $schema_value_key);
        $b_operation_params = $this->operationSchemaParameters($b_open_api, $operation_key, $param_in, $schema_value_key);

        // for each param...
        foreach ($a_operation_params as $a_operation_param_key => $a_operation_param_value) {
            if (isset($b_operation_params[$a_operation_param_key])) {
                $b_operation_param_value = $b_operation_params[$a_operation_param_key];

                if ($b_operation_param_value) {
                    if (strcmp($a_operation_param_value, $b_operation_param_value) !== 0) {
                        $errors[] = sprintf(
                            '%s: Type mismatch (`%s`|`%s`) for `%s`.',
                            PapiMethods::formatOperationKey($operation_key),
                            $a_operation_param_value,
                            $b_operation_param_value,
                            $a_operation_param_key,
                        );
                    }
                }
            }
        }

        return $errors;
    }

    public function operationParametersRequiredDiff($a_open_api, $b_open_api, $operation_key, $param_in, $param_value_key)
    {
        $errors = [];

        // operationParameters returns an array with format... ['PARAM_NAME' => 'value', ...]
        $a_operation_params = $this->operationParameterProp($a_open_api, $operation_key, $param_in, $param_value_key);
        $b_operation_params = $this->operationParameterProp($b_open_api, $operation_key, $param_in, $param_value_key);

        $diff = array_diff($a_operation_params, $b_operation_params);

        if (count($diff) > 0) {
            $errors[] = sprintf(
                '%s: New required parameter `%s`.',
                PapiMethods::formatOperationKey($operation_key),
                join(', ', array_keys($diff))
            );
        }

        return $errors;
    }

    public function operationParametersForType($open_api, $operation_key, $parameter_type)
    {
        $operation = PapiMethods::getOperation($open_api, $operation_key);

        $operation_all_parameters = $operation->parameters;
        if ($operation_all_parameters) {
            return array_filter($operation_all_parameters, function ($operation_parameter) use ($parameter_type) {
                return $operation_parameter->in === $parameter_type;
            });
        } else {
            return [];
        }
    }

    public function operationParameters($open_api, $operation_key, $parameter_type, $parameter_value_key)
    {
        $operation_parameters = [];

        foreach ($this->operationParametersForType($open_api, $operation_key, $parameter_type) as $parameter) {
            $parameter_object = PapiMethods::objectToArray($parameter);
            $operation_parameters[$parameter->name] = PapiMethods::getNestedValue($parameter_object, $parameter_value_key);
        }

        return $operation_parameters;
    }

    public function operationParameterProp($open_api, $operation_key, $parameter_type, $schema_value_key)
    {
        $operation_schema_parameters = [];

        foreach ($this->operationParametersForType($open_api, $operation_key, $parameter_type) as $parameter) {
            $operation_schema_parameters[$parameter->name] = $parameter->__get($schema_value_key);
        }

        return $operation_schema_parameters;
    }

    public function operationSchemaParameters($open_api, $operation_key, $parameter_type, $schema_value_key)
    {
        $operation_schema_parameters = [];

        foreach ($this->operationParametersForType($open_api, $operation_key, $parameter_type) as $parameter) {
            $operation_schema_parameters[$parameter->name] = $parameter->schema->__get($schema_value_key);
        }

        return $operation_schema_parameters;
    }

    public function operationDiff($a_open_api, $b_open_api, $on_property, $subject)
    {
        $errors = [];

        $matching_operation_keys = iterator_to_array(PapiMethods::matchingOperationKeys($a_open_api, $b_open_api));

        $a_operations = array_filter($matching_operation_keys, function ($operation_key) use ($a_open_api, $on_property) {
            $operation = PapiMethods::getOperation($a_open_api, $operation_key);
            $operation_object = PapiMethods::objectToArray($operation->getSerializableData());

            if (isset($operation_object[$on_property])) {
                return $operation_object[$on_property] !== true;
            } else {
                return true;
            }
        });

        $b_operations = array_filter($matching_operation_keys, function ($operation_key) use ($b_open_api, $on_property) {
            $operation = PapiMethods::getOperation($b_open_api, $operation_key);
            $operation_object = PapiMethods::objectToArray($operation->getSerializableData());

            if (isset($operation_object[$on_property])) {
                return $operation_object[$on_property] !== true;
            } else {
                return true;
            }
        });

        $diff = array_diff($a_operations, $b_operations);

        if (count($diff) > 0) {
            foreach ($diff as $operation_key) {
                $errors[] = sprintf(
                    '%s: This operation has been marked as %s.',
                    PapiMethods::formatOperationKey($operation_key),
                    $subject
                );
            }
        }

        return $errors;
    }
}
