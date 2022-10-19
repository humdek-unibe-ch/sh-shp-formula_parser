-- update plugin version
UPDATE `plugins`
SET version = 'v1.1.0'
WHERE `name` = 'formulaParser';

-- add formula field to style conditionalContainer
INSERT IGNORE INTO `styles_fields` (`id_styles`, `id_fields`, `default_value`, `help`) VALUES (get_style_id('conditionalContainer'), get_field_id('formula'), NULL, 'JSON file with the formula definition');
