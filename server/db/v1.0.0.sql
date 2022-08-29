-- add plugin entry in the plugin table
INSERT IGNORE INTO plugins (name, version) 
VALUES ('formulaParser', 'v1.0.0');

-- Add new style `formulaParser`
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES (NULL, 'formula', get_field_type_id('json'), '0');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('markdown'), get_field_id('formula'), NULL, 'JSON file with the formula definition');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`) VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'calculateFormula', 'Calculate formula', 'StyleModel', 'set_db_fields', 'FormulaParserHooks', 'calculateFormula');
