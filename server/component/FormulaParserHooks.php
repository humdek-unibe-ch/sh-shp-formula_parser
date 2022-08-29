<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";
require_once __DIR__ . "/../ext/math-executor/vendor/autoload.php";

use NXP\MathExecutor;

/**
 * The class to define the hooks for the plugin.
 */
class FormulaParserHooks extends BaseHooks
{
    /* Constructors ***********************************************************/

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
            $calculated_results = array();
            $executor = new MathExecutor();
            $executor->addFunction('sum', function ($arr) {
                return array_sum($arr);
            });
            foreach ($json_formula as $key => $formula_info) {
                if (isset($formula_info['variables'])) {
                    foreach ($formula_info['variables'] as $f_var_name => $f_var_value) {
                        $executor->setVar($f_var_name, $f_var_value);
                    }
                }
                foreach ($calculated_results as $c_var_name => $c_var_value) {
                    $executor->setVar($c_var_name, $c_var_value);
                }
                $calculated_results[$formula_info['result_holder']] = $executor->execute($formula_info['formula']);
            }
            $calculated_results['debug'] = json_encode(
                array(
                    "calculated_results" => $calculated_results,
                    "variables" => $executor->getVars()
                )
            );
            return $calculated_results;
        } catch (Exception $e) {
            // throw $th;
            return array("error" => $e->getMessage());
        }
    }

    private function replace_calced_values($field_content, $calc_formual_values)
    {
        $field_content = preg_replace_callback('~{{.*?}}~s', function ($m) use ($calc_formual_values) {
            $res = trim(str_replace("{{", "", str_replace("}}", "", $m[0])));
            if (isset($calc_formual_values[$res])) {
                return $calc_formual_values[$res];
            } else {
                return '';
            }
            return str_replace(" ", "", $m[0]);
        }, $field_content);
        foreach ($calc_formual_values as $var => $var_value) {
            $field_content = preg_replace('#\{\{' . $var . '\}\}#s', $var_value, $field_content);
        }
        return $field_content;
    }


    /* Public Methods *********************************************************/

    public function calculateFormula($args)
    {
        $fields = $args['fields'];
        $formula_key = array_search('formula', array_column($fields, 'name'));
        $formula_json = $formula_key ? $fields[$formula_key]['content'] : null;
        if (!$formula_json) {
            // if there is no formula we dont need more calculations; return normal execution
            return $this->execute_private_method($args);
        }
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
            "data_config" => $data_config,
            "user_name" => $user_name,
            "user_code" => $user_code
        ));
        $calc_formual_values = $this->calc_formula_values($formula_json);
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
            $field['content'] = $this->execute_private_method(array(
                "hookedClassInstance" => $model,
                "methodName" => "calc_dynamic_values",
                "field" => $field,
                "data_config" => $data_config,
                "user_name" => $user_name,
                "user_code" => $user_code
            ));

            $field['content'] = $this->replace_calced_values($field['content'], $calc_formual_values);
            // $field['content'] = $model->calc_dynamic_values($field, $data_config, $user_name, $user_code);

            // $field['content'] = 

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
