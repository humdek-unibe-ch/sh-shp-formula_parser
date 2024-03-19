-- update plugin version
UPDATE `plugins`
SET version = 'v1.5.0'
WHERE `name` = 'formulaParser';

DELETE FROM hooks
WHERE `name` = 'formula-calc-get-debug'