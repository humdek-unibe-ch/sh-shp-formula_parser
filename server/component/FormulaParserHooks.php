<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../ext/math-executor/vendor/autoload.php";
require_once __DIR__ . "/../ext/php-math/vendor/autoload.php";

use NXP\MathExecutor;
use MathPHP\Probability\Distribution\Continuous;
use MathPHP\Statistics\Descriptive;

/**
 * The class to define the hooks for the plugin.
 */
class FormulaParserHooks extends BaseHooks
{
    /* Constructors ***********************************************************/

    /* Private Properties *****************************************************/

    /**
     * The math executor instance
     */
    private $executor;

    /**
     * The constructor creates an instance of the hooks.
     * @param object $services
     *  The service handler instance which holds all services
     * @param object $params
     *  Various params
     */
    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);        
    }

    /* Private Methods *********************************************************/

    private function init_math_executor(){
        $this->executor = new MathExecutor();
        $this->set_math_functions();
        $this->executor->setVarValidationHandler(function (string $name, $variable) {
            // allow all scalars, array and null
            if (is_numeric($variable) || is_array($variable)) {
                return;
            }
            throw new Exception("Invalid variable type");
        });
    }

    private function set_math_functions()
    {
        //Array sum function
        $this->executor->addFunction('sum', function ($arr) {
            return array_sum($arr);
        });

        //Array order function
        $this->executor->addFunction('order', function ($arr, $key, $sort_type) {
            try {
                if (!is_array($arr)) {
                    return array("error" => 'First parameter is not array');
                } else if (!($sort_type == "SORT_ASC" || $sort_type == "SORT_DESC")) {
                    return array("error" => 'Third parameter is not SORT_ASC or SORT_DESC');
                } else {
                    $arr_column = array_column($arr, $key);
                    if (count($arr_column) != count($arr)) {
                        return array("error" => 'Second parameter is not correct');
                    }
                    $sort_type = $sort_type == "SORT_DESC" ? SORT_DESC : SORT_ASC;
                    array_multisort($arr_column, $sort_type, $arr);
                    return $arr;
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });

        // CDF - Normal distribution
        $this->executor->addFunction('normal_cdf', function ($x, $mu, $sigma) {
            try {
                if (is_numeric($x) && is_numeric($mu) && is_numeric($sigma)) {
                    $normal = new Continuous\Normal($mu, $sigma);
                    return $normal->cdf($x);
                } else {
                    return array("error" => 'Some of the passed parameters are not numeric');
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });

        // Standard deviation (For a sample; uses sample variance)
        $this->executor->addFunction('standardDeviation', function ($values) {
            try {
                return Descriptive::standardDeviation($values, true);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });

        // Re rank values based on a table
        $this->executor->addFunction('re_rank', function ($table, $value, $key) {
            try {
                $result = null;
                foreach ($table as $t_key => $t_value) {
                    if (!is_numeric($value) || !is_numeric($t_key)) {
                        return array("error" => $value . ' or ' . $t_key . ' is not numeric');
                    } else {
                        if ($value >= $t_key) {
                            if (isset($t_value[$key])) {
                                $result = $t_value[$key];
                            } else {
                                return array("error" => 'Wrong key');
                            }
                        }
                    }
                }
                return $result;
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Check if there is dynamic data that should be calculated. If there are it is calculated and returned
     * @param object $field
     * The field which we are checking
     * @param string $json_formula
     * The json formula
     * @return string
     * Return the field content
     */
    private function calc_formula_values($json_formula)
    {
        // replace the field content with the global variables
        try {
            $json_formula = json_decode($json_formula, true);
            if (!$json_formula) {
                return array("debug" => array("error" => 'Not a valid JSON formula'));
            }
            $calculated_results = array();
            $this->executor->removeVars();
            foreach ($json_formula as $key => $formula_info) {
                if (isset($formula_info['variables'])) {
                    foreach ($formula_info['variables'] as $f_var_name => $f_var_value) {
                        $this->executor->setVar($f_var_name, $f_var_value);
                    }
                }
                foreach ($calculated_results as $c_var_name => $c_var_value) {
                    $this->executor->setVar($c_var_name, $c_var_value);
                }
                if (is_array($formula_info) && isset($formula_info['result_holder'])) {
                    try {
                        $calculated_results[$formula_info['result_holder']] = $this->executor->execute($formula_info['formula']);
                    } catch (Exception $e) {
                        $calculated_results[$formula_info['result_holder']] = array("error" => $e->getMessage());
                    }
                }
            }
            $calculated_results['debug'] = json_encode(
                array(
                    "calculated_results" => $calculated_results,
                    "variables" => $this->executor->getVars()
                )
            );
            return $calculated_results;
        } catch (Exception $e) {
            // throw $th;
            return array("debug" => array("error" => $e->getMessage()));
        }
    }

    /* Public Methods *********************************************************/

    /**
     * Calculate formula and set the value in db_fields
     * @param array $args
     * all the parameters
     */
    public function calculateFormula($args)
    {
        $fields = $args['fields'];
        $formula_key = array_search('formula', array_column($fields, 'name'));
        $formula_json = $formula_key ? $fields[$formula_key]['content'] : null;
        if (!$formula_json) {
            // if there is no formula we dont need more calculations; return normal execution
            return $this->execute_private_method($args);
        }
        $this->init_math_executor();
        $model = $args['hookedClassInstance'];
        $user_name = $model->db->fetch_user_name();
        $user_code = $model->db->get_user_code();
        $data_config_key = array_search('data_config', array_column($fields, 'name'));
        $data_config = $data_config_key ? $fields[$data_config_key]['content'] : null;
        if ($data_config) {
            // if data_config is set replace if there are any globals
            $data_config = str_replace('@user_code', $user_code, $data_config);
            $data_config = str_replace('@project', $_SESSION['project'], $data_config);
            $data_config = str_replace('@user', $user_name, $data_config);
            $data_config = json_decode($data_config, true);
        }
        $formula_json = $this->execute_private_method(array(
            "hookedClassInstance" => $model,
            "methodName" => "calc_dynamic_values",
            "field" => $fields[$formula_key],
            "data_config" => $data_config ? $data_config : array(),
            "user_name" => $user_name,
            "user_code" => $user_code
        ));
        $calc_formula_values = $this->calc_formula_values($formula_json);
        foreach ($fields as $field) {
            // set style info
            $this->set_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "style_name",
                "propertyNewValue" => $field['style'],
            ));
            $this->set_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "style_type",
                "propertyNewValue" => $field['type'],
            ));
            $this->set_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "section_name",
                "propertyNewValue" => $field['section_name'],
            ));

            // load dynamic data if needed
            if ($data_config) {
                $field['content'] = $this->execute_private_method(array(
                    "hookedClassInstance" => $model,
                    "methodName" => "calc_dynamic_values",
                    "field" => $field,
                    "data_config" => $data_config ? $data_config : array(),
                    "user_name" => $user_name,
                    "user_code" => $user_code
                ));
            }

            $field['content'] = $field['content'] ? $field['content'] : '';

            if ($calc_formula_values) {
                $field['content'] = $this->execute_private_method(array(
                    "hookedClassInstance" => $model,
                    "methodName" => "replace_calced_values",
                    "field_content" => $field['content'],
                    "calc_formula_values" => $calc_formula_values
                ));
            }

            $entry_record = $this->get_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "entry_record",
            ));

            $default = $field["default_value"] ?? "";
            if ($field['name'] == "url") {
                $field['content'] = $model->get_url($field['content']);
            } else if ($field['type'] == "markdown" && (!$entry_record || count($entry_record) == 0)) {
                $field['content'] = $model->parsedown->text($field['content']);
            } else if ($field['type'] == "markdown-inline" && (!$entry_record || count($entry_record) == 0)) {
                $field['content'] = $model->parsedown->line($field['content']);
            } else if ($field['type'] == "json") {
                $field['content'] = $field['content'] ? json_decode($field['content'], true) : array();
                /* $field['content'] = $this->json_style_parse($field['content']); */
            } else if ($field['type'] == "condition") {
                $field['content'] = $field['content'] ? json_decode($field['content'], true) : array();
            } else if ($field['type'] == "data-config") {
                $field['content'] = $field['content'] ? json_decode($field['content'], true) : array();
            } else if ($model->user_input->is_new_ui_enabled() && $model->is_link_active("cmsUpdate") && $field['name'] == "css") {
                // if it is the new UI and in edit mode remove the custom css for better visibility
                $field['content'] = '';
            }
            $this->set_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "db_fields",
                "propertyNewValue" => array(
                    "content" => $field['content'],
                    "type" => $field['type'],
                    "id" => $field['id'],
                    "default" => $default,
                ),
                "arrayKey" => $field['name']
            ));
        }
    }
}
?>
