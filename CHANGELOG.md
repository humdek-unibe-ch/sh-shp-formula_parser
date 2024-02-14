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
