-- add plugin entry in the plugin table
INSERT IGNORE INTO plugins (name, version) 
VALUES ('formulaParser', 'v1.0.0');

-- Add new style `loop`
INSERT IGNORE INTO `styles` (`name`, `id_type`, id_group, description) VALUES ('loop', '2', (select id from styleGroup where `name` = 'Wrapper' limit 1), 'A style which takes an array object and loop the rows and load its children passing the values of the rows');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('formula'), NULL, 'JSON file with the formula definition');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('children'), 0, 'Children that can be added to the style. Each child will be loaded as a page');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('css'), NULL, 'Allows to assign CSS classes to the root item of the style.');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('css_mobile'), NULL, 'Allows to assign CSS classes to the root item of the style for the mobile version.');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('condition'), NULL, 'The field `condition` allows to specify a condition. Note that the field `condition` is of type `json` and requires\n1. valid json syntax (see https://www.json.org/)\n2. a valid condition structure (see https://github.com/jwadhams/json-logic-php/)\n\nOnly if a condition resolves to true the sections added to the field `children` will be rendered.\n\nIn order to refer to a form-field use the syntax `"@__form_name__#__from_field_name__"` (the quotes are necessary to make it valid json syntax) where `__form_name__` is the value of the field `name` of the style `formUserInput` and `__form_field_name__` is the value of the field `name` of any form-field style.');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('data_config'), '', 
'In this ***JSON*** field we can configure a data retrieve params from the DB, either `static` or `dynamic` data. Example: 
 ```
 [
	{
		"type": "static|dynamic",
		"table": "table_name | #url_param1",
        "retrieve": "first | last | all",
		"fields": [
			{
				"field_name": "name | #url_param2",
				"field_holder": "@field_1",
				"not_found_text": "my field was not found"				
			}
		]
	}
]
```
If the page supports parameters, then the parameter can be accessed with `#` and the name of the paramer. Example `#url_param_name`. 

In order to inlcude the retrieved data in the input `value`, include the `field_holder` that wa defined in the markdown text.

We can access multiple tables by adding another element to the array. The retrieve data from the column can be: `first` entry, `last` entry or `all` entries (concatenated with ;);

`It is used for prefil of the default value`');

-- add loop field to style loop
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES (NULL, 'loop', get_field_type_id('json'), '0');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('loop'), get_field_id('loop'), NULL, 'Json array object as each entry represnts a row which is passed to the children');

-- add formula field to style markdown
INSERT IGNORE INTO `fieldType` (`id`, `name`, `position`) VALUES (NULL, 'formula', '20');
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES (NULL, 'formula', get_field_type_id('formula'), '0');
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('markdown'), get_field_id('formula'), NULL, 'JSON file with the formula definition');

-- add formula field to style graph
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('graph'), get_field_id('formula'), NULL, 'JSON file with the formula definition');

-- Add new style `formulaConfigBuilder`
INSERT IGNORE INTO `styles` (`name`, `id_type`, id_group, description) VALUES ('formulaConfigBuilder', '1', (select id from styleGroup where `name` = 'intern' limit 1), 'Internal style used for the `formula` field');

INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`) VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'calculateFormula', 'Calculate formula', 'StyleModel', 'set_db_fields', 'FormulaParserHooks', 'calculateFormula');
-- register hook  for select-qualtrics-survey field
INSERT IGNORE INTO `hooks` (`id_hookTypes`, `name`, `description`, `class`, `function`, `exec_class`, `exec_function`) VALUES ((SELECT id FROM lookups WHERE lookup_code = 'hook_overwrite_return' LIMIT 0,1), 'field-formula-edit', 'Output formula config - edit mdoe', 'CmsView', 'create_field_form_item', 'FormulaParserHooks', 'outputFieldFormulaConfigEdit');