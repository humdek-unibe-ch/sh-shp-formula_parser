-- update plugin version
UPDATE `plugins`
SET version = 'v1.3.0'
WHERE `name` = 'formulaParser';

-- update priority for the Formula parser hooks to be with higher priority
UPDATE hooks
SET priority = 9
WHERE `exec_class` = 'FormulaParserHooks';
