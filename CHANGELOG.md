# v1.5.3
 - change `scope` to be add it with `.` instead of `_` as prefix
 - better output for `debugging` when the json_formula fails;

# v1.5.2
 - accept `Boolean` variables as result and use them in later calculations

# v1.5.1 - Requires SelfHelp v6.16.4+
 - #8 add function `is_user_in_group`. It takes `group_name` as a `string` parameter. 

# v1.5.0
 - remove `hook` `formula-calc-get-debug`

# v1.4.3
 - properly return `array` in `if` statement
 - #2 add function `modify_date` - the function expects 3 or 4 parameters.  
   - `date` - string or array with multiple dates 
   - `current_format` - string the current format of the date
   - `modification`- string for modifying the date. [Examples](https://www.php.net/manual/en/datetime.modify.php)
   - `new_format` -  if not set it will use the `current_format`

# v1.4.2
 - improve `README.md` formatting
 - improve `date_format`, now it checks if an `array` or comma separated string is passed. Return the same type based on the passed variable, wither array or a comma separated string.

# v1.4.1
 - properly check if a formula is set when preparing the interpolation data

# v1.4.0 - Requires SelfHelp v6.10.0+
 - add `formula` field to style `dataContainer`. Style loop was moved to the core SelfHelp in v6.10.0
 - add `hooks` to show the formula results when `debug` is enabled

# v1.3.3
 - properly load `.json` schema

# v1.3.2
 - load plugin version using `BaseHook` class

# v1.3.1
 - in `if` statement return result as string if it cannot be executed

# v1.3.0
 - update priority for the Formula parser hooks to be with higher priority

# v1.2.0
 - add `formula` field to style `loop`. Style loop was moved to the core SelfHelp in v5.4.0

# v1.1.6
 - fix spaces in the `textarea`

# v1.1.5
 - properly return negative time when calculated with `calc_time_diff`
 - add example for `calc_time_diff`

# v1.1.4
 - add function `array_filter_by_value` used to filter array.

# v1.1.3
 - add function `count` used to return the length of an array    

# v1.1.2
 - do not check calculations in CMS

# v1.1.1
 - adjust function `sum` to accept array or comma separated sequence of numbers, Check examples in the `README`

# v1.1.0

### New Features
 - add new function `get_current_date` - Get the current date
  - parameters: `format` - the date format of the current date, ex: `Y-m-d`, `d-m-Y`, `Y-m-d H:i`
 - add `formula` field to style `conditionalContainer`

# v1.0.0

### New Features
 - add new style `loop` which takes an array object and loop the rows and load its children passing the values of the rows
 - The Formula Parser  based on [MathExecutor](https://github.com/neonxp/MathExecutor)
