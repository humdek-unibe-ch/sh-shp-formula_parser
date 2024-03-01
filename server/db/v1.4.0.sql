-- update plugin version
UPDATE `plugins`
SET version = 'v1.4.0'
WHERE `name` = 'formulaParser';

-- add formula field to style dataContainer
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('dataContainer'), get_field_id('formula'), NULL, 'JSON file with the formula definition');

-- register hook  for add-calc-formula-iterpolation-data
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`) VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'formula-calc-add-iterpolation-data', 'Calculate formula and add to interpolation data', 'StyleModel', 'get_interpolation_data', 'FormulaParserHooks', 'addFormulaCalc');

-- register hook  for debug ouput
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`) VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'formula-calc-get-debug', 'Calculate formula and add to interpolation data', 'StyleModel', 'get_debug_data', 'FormulaParserHooks', 'get_debug_data');