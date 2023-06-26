<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/CustomMathFunctions.php";

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

    private function init_math_executor()
    {
        $customMathFunctions = new CustomMathFunctions($this->services);
        $this->executor = $customMathFunctions->get_executor();
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
                    } catch (\Throwable $e) { // For PHP 7
                        $calculated_results[$formula_info['result_holder']] = array("error" => $e->getMessage());
                        break;
                    } catch (Exception $e) {
                        $calculated_results[$formula_info['result_holder']] = array("error" => $e->getMessage());
                        break;
                    }
                }
            }
            $calculated_results['debug'] = json_encode(
                array(
                    "calculated_results" => $calculated_results,
                    "variables" => $this->executor->getVars(),
                    "json_formula" => $json_formula
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
        $this->execute_private_method($args);
        if ($this->is_cms_page()) {
            // do not calculate in cms
            return;
        }
        $adjusted_fields = $this->execute_private_method(array(
            "hookedClassInstance" => $model,
            "methodName" => "get_db_fields"
        ));
        $user_name = $model->db->fetch_user_name();
        $user_code = $model->db->get_user_code();
        $data_config = isset($adjusted_fields['data_config']) ? json_encode($adjusted_fields['data_config']['content']) : null;
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
        foreach ($adjusted_fields as $field_name => $field) {
            // if ($field['type'] != STYLE_TYPE_INTERNAL) {
            if ($field['content']) {
                $field['content'] = $this->services->get_db()->replace_calced_values($field['content'], $calc_formula_values);
            }
            $this->set_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "db_fields",
                "propertyNewValue" => $field,
                "arrayKey" => $field_name
            ));
        }
    }

    /**
     * Return a BaseStyleComponent object
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFormulaConfigEdit($args)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);
        if ($field['name'] == 'formula') {
            $field_name_prefix = "fields[" . $field['name'] . "][" . $field['id_language'] . "]" . "[" . $field['id_gender'] . "]";
            $formulaConfigBuilder = new BaseStyleComponent("formulaConfigBuilder", array(
                "value" => $field['content'],
                "name" => $field_name_prefix . "[content]"
            ));
            if ($formulaConfigBuilder && $res) {
                $children = $res->get_view()->get_children();
                $children[] = $formulaConfigBuilder;
                $res->get_view()->set_children($children);
            }
        }
        return $res;
    }

    /**
     * Return a BaseStyleComponent object
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFormulaConfigView($args)
    {
        $field = $this->get_param_by_name($args, 'field');
        $res = $this->execute_private_method($args);
        if ($field['name'] == 'formula') {
            $formulaConfigBuilder = new BaseStyleComponent("rawText", array(
                "text" => $field['content'] && $field['content'] != 'null' ? 'exists' : $field['content']
            ));
            if ($formulaConfigBuilder && $res) {
                $children = $res->get_view()->get_children();
                array_pop($children); // remove last element because it is the whole value of the formula
                $children[] = $formulaConfigBuilder;
                $res->get_view()->set_children($children);
            }
        }
        return $res;
    }
}
?>
