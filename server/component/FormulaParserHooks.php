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
     * Values larger than this are truncated/omitted from formula_debug.
     */
    private const DEBUG_VALUE_MAX_BYTES = 8192;

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
     * Coerce values to types MathExecutor accepts (null, scalar, array).
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_executor_var($value)
    {
        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            // stdClass / JSON objects → associative array for json_get / formulas
            return json_decode(json_encode($value), true);
        }
        return null;
    }

    /**
     * Truncate large scalars/arrays for debug payloads so formula_debug does not keep multi-MB copies.
     *
     * @param mixed $value
     * @return mixed
     */
    private function truncate_for_debug($value)
    {
        if (is_string($value)) {
            if (strlen($value) > self::DEBUG_VALUE_MAX_BYTES) {
                return substr($value, 0, self::DEBUG_VALUE_MAX_BYTES)
                    . '...[truncated ' . strlen($value) . ' bytes]';
            }
            return $value;
        }
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[$k] = $this->truncate_for_debug($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Resolve a single {{placeholder}} against a lookup map. Exact match only.
     *
     * @param string $placeholder e.g. "{{upload._json}}"
     * @param array $lookup
     * @return array{ok:bool,value?:mixed}
     */
    private function resolve_exact_placeholder($placeholder, $lookup)
    {
        if (!is_string($placeholder)) {
            return array("ok" => false);
        }
        if (!preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', trim($placeholder), $m)) {
            return array("ok" => false);
        }
        $key = trim($m[1]);
        if (!array_key_exists($key, $lookup)) {
            return array("ok" => true, "value" => null, "key" => $key, "missing" => true);
        }
        return array("ok" => true, "value" => $lookup[$key], "key" => $key, "missing" => false);
    }

    /**
     * Walk a dotted path on decoded JSON (same semantics as json_get).
     *
     * @param mixed $data
     * @param string $path
     * @return array{ok:bool,found:bool,value?:mixed,error?:string}
     */
    private function get_value_by_path($data, $path)
    {
        if ($path === null || $path === '') {
            return array("ok" => true, "found" => true, "value" => $data);
        }
        if (!is_string($path)) {
            return array("ok" => false, "found" => false, "error" => 'Path must be a string');
        }
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if ($segment === '') {
                return array("ok" => false, "found" => false, "error" => 'Invalid path: empty segment');
            }
            if (!is_array($current)) {
                return array("ok" => true, "found" => false, "value" => null);
            }
            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (ctype_digit($segment) && array_key_exists((int) $segment, $current)) {
                $current = $current[(int) $segment];
            } else {
                return array("ok" => true, "found" => false, "value" => null);
            }
        }
        return array("ok" => true, "found" => true, "value" => $current);
    }

    /**
     * Decode JSON input without keeping both string and array longer than needed.
     * Retries stripslashes / double-encoding — SelfHelp replace_calced_values uses
     * addslashes which makes raw survey _json fail with "Syntax error".
     *
     * @param mixed $json
     * @return array{ok:bool,value?:mixed,error?:string}
     */
    private function decode_json_input($json)
    {
        if (is_array($json)) {
            return array("ok" => true, "value" => $json);
        }
        if (is_object($json)) {
            return array("ok" => true, "value" => json_decode(json_encode($json), true));
        }
        if (!is_string($json)) {
            return array("ok" => false, "error" => 'JSON input must be a string or array');
        }
        $json = trim($json);
        // Strip UTF-8 BOM
        if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
            $json = substr($json, 3);
        }
        if ($json === '') {
            return array("ok" => false, "error" => 'Empty JSON string');
        }

        $candidates = array($json);
        $stripped = stripslashes($json);
        if ($stripped !== $json) {
            $candidates[] = $stripped;
        }

        $last_error = 'Syntax error';
        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Double-encoded JSON string → decode once more
                if (is_string($decoded)) {
                    $inner = json_decode($decoded, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return array("ok" => true, "value" => $inner);
                    }
                }
                return array("ok" => true, "value" => $decoded);
            }
            $last_error = json_last_error_msg();
        }

        return array("ok" => false, "error" => 'Invalid JSON: ' . $last_error);
    }

    /**
     * Parse a simple json_get default literal ([], {}, null, number, quoted string).
     *
     * @param string|null $expr
     * @return mixed
     */
    private function parse_json_get_default($expr)
    {
        if ($expr === null || $expr === '') {
            return null;
        }
        $expr = trim($expr);
        if ($expr === '[]') {
            return array();
        }
        if ($expr === '{}') {
            return new \stdClass();
        }
        if ($expr === 'null') {
            return null;
        }
        if ($expr === 'true') {
            return true;
        }
        if ($expr === 'false') {
            return false;
        }
        if (is_numeric($expr)) {
            return $expr + 0;
        }
        if (
            (substr($expr, 0, 1) === "'" && substr($expr, -1) === "'")
            || (substr($expr, 0, 1) === '"' && substr($expr, -1) === '"')
        ) {
            return substr($expr, 1, -1);
        }
        $decoded = json_decode($expr, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $expr;
    }

    /**
     * Memory-safe path for formulas that are only:
     *   json_get($var, 'path', default)
     * with $var bound to an exact {{lookup.key}} placeholder.
     * Prefer MySQL JSON_EXTRACT so PHP never loads the full `_json` blob.
     *
     * @param array $formula_info
     * @param array &$lookup Retrieved data map (large keys may be unset)
     * @param object|null $model
     * @param array|null $data_config
     * @return array{handled:bool,value?:mixed}
     */
    private function try_memory_safe_json_get(array $formula_info, array &$lookup, $model = null, $data_config = null)
    {
        if (!isset($formula_info['formula'], $formula_info['result_holder'], $formula_info['variables'])) {
            return array("handled" => false);
        }
        $formula = trim($formula_info['formula']);
        // Allow 'path' or "path"
        if (!preg_match(
            "/^json_get\\(\\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\\s*,\\s*(['\"])([^'\"]*)\\2\\s*(?:,\\s*(.+))?\\s*\\)$/s",
            $formula,
            $m
        )) {
            return array("handled" => false);
        }

        $var_name = $m[1];
        $path = $m[3];
        $default_expr = isset($m[4]) ? $m[4] : null;
        $has_default = array_key_exists(4, $m);

        if (!isset($formula_info['variables'][$var_name])) {
            return array("handled" => false);
        }

        $var_def = $formula_info['variables'][$var_name];
        $source_key = null;

        if (is_array($var_def) && isset($var_def['source'])) {
            $resolved = $this->resolve_exact_placeholder($var_def['source'], $lookup);
            if (!$resolved['ok']) {
                // Still allow SQL extract using the placeholder key even if not in lookup
                if (!preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', trim($var_def['source']), $pm)) {
                    return array("handled" => false);
                }
                $source_key = trim($pm[1]);
            } else {
                $source_key = $resolved['key'] ?? null;
            }
            if (isset($var_def['json_path']) && $var_def['json_path'] !== '') {
                $path = $var_def['json_path'];
            }
            if (!$has_default && array_key_exists('default', $var_def)) {
                $has_default = true;
                $default_expr = null;
                $default_value = $var_def['default'];
            }
        } elseif (is_string($var_def)) {
            $resolved = $this->resolve_exact_placeholder($var_def, $lookup);
            if (!$resolved['ok']) {
                return array("handled" => false);
            }
            $source_key = $resolved['key'] ?? null;
        } else {
            return array("handled" => false);
        }

        // Prefer data_config lookup via get_dataTable_with_filter for `*_json` columns
        if ($model && $data_config && $source_key
            && ($source_key === '_json' || substr($source_key, -5) === '_json')
        ) {
            $sql_result = $this->fetch_json_path_via_sql($model, $data_config, $source_key, $path);
            if ($sql_result['ok']) {
                if (array_key_exists($source_key, $lookup)) {
                    unset($lookup[$source_key]);
                }
                if (empty($sql_result['found'])) {
                    if ($has_default) {
                        if (isset($default_value)) {
                            return array("handled" => true, "value" => $default_value);
                        }
                        return array("handled" => true, "value" => $this->parse_json_get_default($default_expr));
                    }
                    return array("handled" => true, "value" => array("error" => 'Path not found: ' . $path));
                }
                return array("handled" => true, "value" => $sql_result['value']);
            }
            if (!array_key_exists($source_key, $lookup)) {
                return array("handled" => true, "value" => array("error" => $sql_result['error']));
            }
        }

        // Fallback: value already in lookup (small JSON only)
        $source = ($source_key !== null && array_key_exists($source_key, $lookup))
            ? $lookup[$source_key]
            : null;
        if ($source_key !== null && array_key_exists($source_key, $lookup)) {
            unset($lookup[$source_key]);
        }

        $decoded = $this->decode_json_input($source);
        unset($source);
        if (!$decoded['ok']) {
            return array("handled" => true, "value" => array("error" => $decoded['error']));
        }

        $lookup_result = $this->get_value_by_path($decoded['value'], $path);
        unset($decoded);
        if (!$lookup_result['ok']) {
            return array("handled" => true, "value" => array("error" => $lookup_result['error']));
        }
        if (!$lookup_result['found']) {
            if ($has_default) {
                if (isset($default_value)) {
                    return array("handled" => true, "value" => $default_value);
                }
                return array("handled" => true, "value" => $this->parse_json_get_default($default_expr));
            }
            return array("handled" => true, "value" => array("error" => 'Path not found: ' . $path));
        }

        return array("handled" => true, "value" => $lookup_result['value']);
    }

    /**
     * Resolve a variable definition against the lookup map without embedding large values into the formula JSON.
     *
     * @param mixed $var_def
     * @param array &$lookup
     * @param object $db
     * @param object|null $model
     * @param array|null $data_config
     * @return mixed
     */
    private function resolve_variable_definition($var_def, array &$lookup, $db, $model = null, $data_config = null)
    {
        // Structured extract: { source, json_path, default }
        if (is_array($var_def) && isset($var_def['source'])) {
            $resolved = $this->resolve_exact_placeholder($var_def['source'], $lookup);
            $source_key = $resolved['key'] ?? null;
            if (!$resolved['ok'] && is_string($var_def['source'])
                && preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', trim($var_def['source']), $pm)
            ) {
                $source_key = trim($pm[1]);
            }

            if (isset($var_def['json_path']) && $var_def['json_path'] !== ''
                && $model && $data_config && $source_key
                && ($source_key === '_json' || substr($source_key, -5) === '_json')
            ) {
                $sql_result = $this->fetch_json_path_via_sql($model, $data_config, $source_key, $var_def['json_path']);
                if ($sql_result['ok']) {
                    if (array_key_exists($source_key, $lookup)) {
                        unset($lookup[$source_key]);
                    }
                    if (empty($sql_result['found'])) {
                        return array_key_exists('default', $var_def) ? $var_def['default'] : null;
                    }
                    return $sql_result['value'];
                }
            }

            $source = ($resolved['ok'] && empty($resolved['missing']))
                ? $resolved['value']
                : null;
            if ($source_key !== null && array_key_exists($source_key, $lookup)) {
                unset($lookup[$source_key]);
            }
            if (isset($var_def['json_path'])) {
                $decoded = $this->decode_json_input($source);
                unset($source);
                if (!$decoded['ok']) {
                    return array("error" => $decoded['error']);
                }
                $path_result = $this->get_value_by_path($decoded['value'], $var_def['json_path']);
                unset($decoded);
                if (!$path_result['ok']) {
                    return array("error" => $path_result['error']);
                }
                if (!$path_result['found']) {
                    return array_key_exists('default', $var_def) ? $var_def['default'] : null;
                }
                return $path_result['value'];
            }
            return $source;
        }

        if (is_string($var_def)) {
            $resolved = $this->resolve_exact_placeholder($var_def, $lookup);
            if ($resolved['ok']) {
                // Exact {{key}} — return raw lookup value (never addslashes via replace_calced_values)
                return empty($resolved['missing']) ? $resolved['value'] : null;
            }
            // Mixed templates: resolve known {{keys}} by direct lookup only (no addslashes).
            return preg_replace_callback(
                '/\{\{\s*([^{}]+?)\s*\}\}/',
                function ($m) use ($lookup) {
                    $key = trim($m[1]);
                    if (array_key_exists($key, $lookup)) {
                        $val = $lookup[$key];
                        return is_scalar($val) || $val === null ? (string) $val : json_encode($val);
                    }
                    return $m[0];
                },
                $var_def
            );
        }

        return $var_def;
    }

    /**
     * Calculate formula values from an already-decoded formula config and a data lookup map.
     * Avoids string-injecting multi-MB JSON into the formula document.
     *
     * @param array $json_formula
     * @param array $lookup
     * @param object $db
     * @return array
     */
    private function calc_formula_values_from_config(array $json_formula, array $lookup, $db, $model = null, $data_config = null)
    {
        $calculated_results = array();
        $this->executor->removeVars();
        $released_keys = array();

        foreach ($json_formula as $formula_info) {
            if (!is_array($formula_info)) {
                continue;
            }

            // Fast path: json_get($var, 'path', default) with {{source}} — SQL extract when possible
            if (isset($formula_info['result_holder'])) {
                $safe = $this->try_memory_safe_json_get($formula_info, $lookup, $model, $data_config);
                if (!empty($safe['handled'])) {
                    $calculated_results[$formula_info['result_holder']] = $safe['value'];
                    if (isset($formula_info['variables']) && is_array($formula_info['variables'])) {
                        foreach (array_keys($formula_info['variables']) as $released) {
                            $released_keys[] = $released;
                        }
                    }
                    continue;
                }
            }

            if (isset($formula_info['variables']) && is_array($formula_info['variables'])) {
                foreach ($formula_info['variables'] as $f_var_name => $f_var_value) {
                    $resolved = $this->normalize_executor_var(
                        $this->resolve_variable_definition($f_var_value, $lookup, $db, $model, $data_config)
                    );
                    $this->executor->setVar($f_var_name, $resolved);
                }
            }
            foreach ($calculated_results as $c_var_name => $c_var_value) {
                if ($c_var_name === 'formula_debug') {
                    continue;
                }
                $this->executor->setVar($c_var_name, $this->normalize_executor_var($c_var_value));
            }
            if (isset($formula_info['result_holder'], $formula_info['formula'])) {
                try {
                    $calculated_results[$formula_info['result_holder']] = $this->executor->execute($formula_info['formula']);
                } catch (\Throwable $e) {
                    $calculated_results[$formula_info['result_holder']] = array("error" => $e->getMessage());
                    break;
                }
            }

            // Drop large source vars after use when requested or when name ends with _json
            if (isset($formula_info['release_variables']) && is_array($formula_info['release_variables'])) {
                foreach ($formula_info['release_variables'] as $release_name) {
                    $this->executor->removeVar($release_name);
                    $released_keys[] = $release_name;
                }
            } elseif (isset($formula_info['variables']) && is_array($formula_info['variables'])) {
                foreach (array_keys($formula_info['variables']) as $var_name) {
                    if (substr($var_name, -5) === '_json' || $var_name === 'upload_json') {
                        $this->executor->removeVar($var_name);
                        $released_keys[] = $var_name;
                    }
                }
            }
        }

        $calculated_results['formula_debug'] = array(
            "calculated_results" => $this->truncate_for_debug($calculated_results),
            "variables" => $this->truncate_for_debug($this->executor->getVars()),
            "json_formula" => $this->truncate_for_debug($json_formula),
            "released_variables" => array_values(array_unique($released_keys)),
            "memory_safe" => true
        );

        return $calculated_results;
    }

    /**
     * Replace #param placeholders in a data_config array using the model's route params
     * (mirrors StyleModel::parse_params).
     *
     * @param object $model
     * @param array $data_config
     * @return array
     */
    private function parse_data_config_params($model, array $data_config)
    {
        $params = method_exists($model, 'get_params') ? $model->get_params() : array();
        if (!is_array($params) || empty($params)) {
            return $data_config;
        }
        $str_data = json_encode($data_config);
        if (!is_string($str_data) || $str_data === '') {
            return $data_config;
        }
        if (preg_match_all('~#(\w+)\b~', $str_data, $matches)) {
            foreach ($matches[1] as $param_name) {
                if (isset($params[$param_name])) {
                    $str_data = str_replace('#' . $param_name, $params[$param_name], $str_data);
                }
            }
        }
        $decoded = json_decode($str_data, true);
        return is_array($decoded) ? $decoded : $data_config;
    }

    /**
     * Extract a JSON path from a data_config `_json` column using core
     * UserInput::get_data() (which calls get_dataTable_with_filter with
     * filter, exclude_deleted, user scoping, and selected_columns).
     *
     * @param object $model
     * @param array|null $data_config
     * @param string $lookup_key e.g. upload._json
     * @param string $json_path e.g. fotos_tour
     * @return array{ok:bool,found?:bool,value?:mixed,error?:string}
     */
    private function fetch_json_path_via_sql($model, $data_config, $lookup_key, $json_path)
    {
        if (!is_array($data_config) || !$lookup_key || !is_string($json_path) || $json_path === '') {
            return array("ok" => false, "error" => 'Missing data_config or json path');
        }
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $json_path)) {
            return array("ok" => false, "error" => 'Invalid json path');
        }

        $scope = '';
        $col = $lookup_key;
        if (strpos($lookup_key, '.') !== false) {
            $parts = explode('.', $lookup_key, 2);
            $scope = $parts[0];
            $col = $parts[1];
        }
        if ($col !== '_json' && substr($col, -5) !== '_json') {
            return array("ok" => false, "error" => 'Not a _json column');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            return array("ok" => false, "error" => 'Invalid column name');
        }

        $user_input = $this->services ? $this->services->get_user_input() : null;
        if (!$user_input && method_exists($model, 'get_user_input')) {
            $user_input = $model->get_user_input();
        }
        if (!$user_input) {
            return array("ok" => false, "error" => 'No user_input service');
        }

        $parsed_configs = $this->parse_data_config_params($model, $data_config);

        foreach ($parsed_configs as $config) {
            if (!is_array($config) || !isset($config['table'])) {
                continue;
            }
            $config_scope = isset($config['scope']) ? $config['scope'] : '';
            if ($scope !== '' && $config_scope !== $scope) {
                continue;
            }

            $table_id = $user_input->get_dataTable_id($config['table']);
            if (!$table_id) {
                continue;
            }

            $filter = "ORDER BY record_id ASC";
            if (isset($config['retrieve']) && $config['retrieve'] === 'last') {
                $filter = "ORDER BY record_id DESC";
            }
            if (isset($config['filter']) && $config['filter'] != '') {
                $filter = $config['filter'];
            }

            $current_user = isset($config['current_user']) ? (bool)$config['current_user'] : true;
            $user_id = isset($_SESSION['id_user']) ? (int)$_SESSION['id_user'] : 0;
            if ($current_user && $user_id <= 0) {
                // Fail closed when current_user is required but no session user is present,
                // because get_dataTable_with_filter treats user_id <= 0 as "all users".
                return array("ok" => true, "found" => false, "value" => null);
            }

            // Delegates to CALL get_dataTable_with_filter(:form_id, :user_id, :filter, 1, :selected_columns)
            $row = $user_input->get_data(
                $table_id,
                $filter,
                $current_user,
                $current_user ? $user_id : null,
                true,
                true,
                $col
            );

            if (!$row || !is_array($row) || !array_key_exists($col, $row) || $row[$col] === null || $row[$col] === '') {
                return array("ok" => true, "found" => false, "value" => null);
            }

            $raw_json = $row[$col];
            unset($row);

            $decoded = $this->decode_json_input($raw_json);
            unset($raw_json);
            if (!$decoded['ok']) {
                return array("ok" => false, "error" => $decoded['error']);
            }

            $path_result = $this->get_value_by_path($decoded['value'], $json_path);
            unset($decoded);
            if (!$path_result['ok']) {
                return array("ok" => false, "error" => $path_result['error']);
            }
            if (!$path_result['found']) {
                return array("ok" => true, "found" => false, "value" => null);
            }

            return array("ok" => true, "found" => true, "value" => $path_result['value']);
        }

        return array("ok" => false, "error" => 'No matching data_config table for ' . $lookup_key);
    }

    /**
     * Build lookup map for formula variable resolution (globals + retrieved data).
     * Does not embed values into the formula JSON string.
     *
     * @param object $model
     * @param array|null $data_config
     * @return array
     */
    private function build_formula_lookup($model, $data_config)
    {
        $lookup = array();
        if (method_exists($model, 'get_global_vars')) {
            $global_vars = $model->get_global_vars();
            if (is_array($global_vars)) {
                $lookup = array_merge($lookup, $global_vars);
            }
        }
        $global_values = $model->db->get_global_values();
        if (is_array($global_values)) {
            $lookup = array_merge($lookup, $global_values);
        }
        if (method_exists($model, 'get_entry_record')) {
            $entry_record = $model->get_entry_record();
            if (is_array($entry_record)) {
                $lookup = array_merge($lookup, $entry_record);
            }
        }
        $interpolation = $this->get_private_property(array(
            "hookedClassInstance" => $model,
            "propertyName" => "interpolation_data"
        ));
        if (is_array($interpolation) && isset($interpolation['data_config_retrieved'])
            && is_array($interpolation['data_config_retrieved'])
        ) {
            $lookup = array_merge($lookup, $interpolation['data_config_retrieved']);
        } elseif ($data_config && method_exists($model, 'retrieve_data')) {
            $retrieved = $model->retrieve_data($data_config);
            if (is_array($retrieved)) {
                $lookup = array_merge($lookup, $retrieved);
            }
        }

        return $lookup;
    }

    /**
     * Check if there is dynamic data that should be calculated. If there are it is calculated and returned
     * @param string $json_formula
     * The json formula
     * @return array
     * Return the field content
     */
    private function calc_formula_values($json_formula)
    {
        try {
            $json_formula_orig = $json_formula;
            if (!$json_formula) {
                return array("formula_debug" => array("error" => 'No formula config'));
            }
            $decoded = json_decode($json_formula, true);
            if (!$decoded) {
                return array("formula_debug" => array(
                    "error" => 'Not a valid JSON formula',
                    "json_formula" => $this->truncate_for_debug($json_formula_orig)
                ));
            }
            // Legacy path (addFormulaCalc): values already embedded — still truncate debug
            $calculated_results = array();
            $this->executor->removeVars();
            foreach ($decoded as $formula_info) {
                if (!is_array($formula_info)) {
                    continue;
                }
                if (isset($formula_info['variables'])) {
                    foreach ($formula_info['variables'] as $f_var_name => $f_var_value) {
                        $this->executor->setVar($f_var_name, $this->normalize_executor_var($f_var_value));
                    }
                }
                foreach ($calculated_results as $c_var_name => $c_var_value) {
                    $this->executor->setVar($c_var_name, $this->normalize_executor_var($c_var_value));
                }
                if (isset($formula_info['result_holder'])) {
                    try {
                        $calculated_results[$formula_info['result_holder']] = $this->executor->execute($formula_info['formula']);
                    } catch (\Throwable $e) {
                        $calculated_results[$formula_info['result_holder']] = array("error" => $e->getMessage());
                        break;
                    }
                }
                if (isset($formula_info['variables']) && is_array($formula_info['variables'])) {
                    foreach (array_keys($formula_info['variables']) as $var_name) {
                        if (substr($var_name, -5) === '_json' || $var_name === 'upload_json') {
                            $this->executor->removeVar($var_name);
                        }
                    }
                }
            }
            $calculated_results['formula_debug'] = array(
                "calculated_results" => $this->truncate_for_debug($calculated_results),
                "variables" => $this->truncate_for_debug($this->executor->getVars()),
                "json_formula" => $this->truncate_for_debug($decoded)
            );
            return $calculated_results;
        } catch (Exception $e) {
            return array("formula_debug" => array("error" => $e->getMessage()));
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
        $formula_json = $formula_key !== false ? $fields[$formula_key]['content'] : null;
        if (!$formula_json) {
            // if there is no formula we dont need more calculations; return normal execution
            return $this->execute_private_method($args);
        }

        if ($this->is_cms_page()) {
            // do not calculate in cms
            return $this->execute_private_method($args);
        }

        $this->init_math_executor();
        $model = $args['hookedClassInstance'];
        // Blank formula content before StyleModel::set_db_fields so calc_dynamic_values
        // does not string-inject data_config blobs (with addslashes) into the formula JSON.
        $args['fields'][$formula_key]['content'] = '';
        $this->execute_private_method($args);
        $adjusted_fields = $this->execute_private_method(array(
            "hookedClassInstance" => $model,
            "methodName" => "get_db_fields"
        ));
        $user_name = $model->db->fetch_user_name();
        $user_code = $model->db->get_user_code();
        $user_email = $model->db->fetch_user_email();
        $data_config = isset($adjusted_fields['data_config']) ? json_encode($adjusted_fields['data_config']['content']) : null;
        if ($data_config) {
            // if data_config is set replace if there are any globals
            $data_config = str_replace('@user_code', $user_code, $data_config);
            $data_config = str_replace('@project', $_SESSION['project'], $data_config);
            $data_config = str_replace('@user_email', $user_email, $data_config);
            $data_config = str_replace('@user', $user_name, $data_config);
            $data_config = json_decode($data_config, true);
        }

        // Resolve {{vars}} via lookup map instead of string-injecting into the formula JSON.
        $parsed_formula = json_decode($formula_json, true);
        if (isset($adjusted_fields['formula'])) {
            $adjusted_fields['formula']['content'] = is_array($parsed_formula) ? $parsed_formula : array();
        }
        if (!$parsed_formula) {
            $calc_formula_values = array("formula_debug" => array(
                "error" => 'Not a valid JSON formula',
                "json_formula" => $this->truncate_for_debug($formula_json)
            ));
        } else {
            $data_config_arr = $data_config ? $data_config : array();
            $lookup = $this->build_formula_lookup(
                $model,
                $data_config_arr
            );
            $calc_formula_values = $this->calc_formula_values_from_config(
                $parsed_formula,
                $lookup,
                $model->db,
                $model,
                $data_config_arr
            );
            unset($lookup);
        }

        // ADD DEBUG INFO (truncated — never store full _json copies)
        $debug_data = $this->get_private_property(array(
            "hookedClassInstance" => $args['hookedClassInstance'],
            "propertyName" => "debug_data"
        ));
        if (!is_array($debug_data)) {
            $debug_data = array();
        }
        // Strip huge retrieved blobs from earlier calc_dynamic_values on other fields
        if (isset($debug_data['data_config_retrieved']) && is_array($debug_data['data_config_retrieved'])) {
            $debug_data['data_config_retrieved'] = $this->truncate_for_debug($debug_data['data_config_retrieved']);
        }
        $debug_data['formulaCalculation'] = $calc_formula_values;
        $this->set_private_property(array(
            "hookedClassInstance" => $args['hookedClassInstance'],
            "propertyName" => "debug_data",
            "propertyNewValue" => $debug_data
        ));
        // ADD DEBUG INFO

        // Do not interpolate formula_debug into page fields
        $replace_values = $calc_formula_values;
        unset($replace_values['formula_debug']);

        foreach ($adjusted_fields as $field_name => $field) {
            if ($field['content']) {
                $field['content'] = $this->services->get_db()->replace_calced_values($field['content'], $replace_values);
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
     * Calculate formula and set the value in interpolation_data
     * @param array $args
     * all the parameters
     */
    public function addFormulaCalc($args)
    {
        if ($this->is_cms_page()) {
            return $this->execute_private_method($args);
        }
        $res = $this->execute_private_method($args);
        $model = $args['hookedClassInstance'];
        $fields = $this->get_private_property(array(
            "hookedClassInstance" => $model,
            "propertyName" => "db_fields"
        ));
        if (isset($fields['formula'])) {
            // Reuse results from calculateFormula when present — re-running on
            // unresolved {{placeholders}} would setVar('{{upload._json}}') and
            // produce Invalid JSON: Syntax error.
            $debug_data = $this->get_private_property(array(
                "hookedClassInstance" => $model,
                "propertyName" => "debug_data"
            ));
            if (is_array($debug_data) && isset($debug_data['formulaCalculation'])
                && is_array($debug_data['formulaCalculation'])
            ) {
                $calc_formula_values = $debug_data['formulaCalculation'];
            } else {
                $this->init_math_executor();
                $formula_json = $fields['formula']['content'];
                $parsed = is_string($formula_json) ? json_decode($formula_json, true) : $formula_json;
                if (is_array($parsed)) {
                    $data_config = isset($fields['data_config']['content'])
                        ? $fields['data_config']['content']
                        : array();
                    if (is_string($data_config)) {
                        $data_config = json_decode($data_config, true);
                    }
                    $data_config_arr = is_array($data_config) ? $data_config : array();
                    $lookup = $this->build_formula_lookup(
                        $model,
                        $data_config_arr
                    );
                    $calc_formula_values = $this->calc_formula_values_from_config(
                        $parsed,
                        $lookup,
                        $model->db,
                        $model,
                        $data_config_arr
                    );
                    unset($lookup);
                } else {
                    $calc_formula_values = $this->calc_formula_values(
                        is_string($formula_json) ? $formula_json : json_encode($formula_json)
                    );
                }
            }
            if (isset($fields['scope'])) {
                // add the scope prefix is set
                $scope = $fields['scope']['content'];
                $scoped_values = array();
                foreach ($calc_formula_values as $key => $value) {
                    $scoped_key = $scope == "" ? $key : ($scope . '.' . $key); // add the scope only if it is set
                    $scoped_values[$scoped_key] = $value;
                }
                $calc_formula_values = $scoped_values;
            }
            if (!$res) {
                $res = array();
            }
            $res = array_merge($res, array(
                "formulaCalculation" => $calc_formula_values
            ));
        } else {
            return $res;
        }
        $this->set_private_property(array(
            "hookedClassInstance" => $model,
            "propertyName" => "interpolation_data",
            "propertyNewValue" => $res,
        ));
        return $res;
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

    /**
     * Get the plugin version
     */
    public function get_plugin_db_version($plugin_name = 'formulaParser')
    {
        return parent::get_plugin_db_version($plugin_name);
    }
}
?>
