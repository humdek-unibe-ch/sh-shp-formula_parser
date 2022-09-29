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
            $field['content'] = $this->services->get_db()->replace_calced_values($field['content'], $calc_formula_values);

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

    /**
     * Return a BaseStyleComponent object
     * @param object $args
     * Params passed to the method
     * @return object
     * Return a BaseStyleComponent object
     */
    public function outputFieldFormulaConfigEdit($args){
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
}
?>
