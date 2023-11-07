# SelfHelp plugin - Formula Parser

The plugin is based on (MathExecutor)[https://github.com/neonxp/MathExecutor]. It supports all the operators and functions that are supported in `MathExecutor`.
The plugin uses (MathPHP)[https://github.com/markrogoyski/math-php] for additional math functions

Additional functions:
 - `sum` (for array) - pass array of values and return their sum
  - parameters: array with values
  - examples: `[
                  {
                      "formula": "sum(1,2,3)",
                      "result_holder": "sum"
                  },
                  {
                      "formula": "sum([1,2,3])",
                      "result_holder": "sum2"
                  }
              ]`
 - `order_array` -  reorder array
  - parameters: `arr`, `key` the name of the key that we will use for sorting, `sort_type` SORT_DESC or SORT_ASC
 - `normal_cdf` - calculate CDF - Normal distribution
  - parameters: `x`, `mu`, `sigma`
 - `standard_deviation`: calculate the standard deviation 
  - parameters: array with values
 - `re_rank` - Re rank values based on a table
  - parameters: `table` - array (the table) with objects (the row), `value` - Current value (number), `key` - The key that we are looking for the new value
 - `calc_time_diff` - calculate time between `time2` and `time1`
  - parameters: `time1` the first date/time, `time2` the second date/time, `unit` the return value could be based on `seconds`, `minutes`, `hours`, `days`, `weeks`, `months`, `years`
 - `calculations_on_rows` - Execute calculations on rows. The required parameters are column arrays. These arrays has the same length and they are looped in the same cycle. This give us the option to take the same row value and manipulate it and create a new column for example. 
  - parameters: `arrays` - Send array which contain all required columns, `formula` The formula that we want to use. The passed arrays are looped and they are in the same loop cycle, which give us the row values. The columns can be used with [number], The first column will be [0], the second will be [1], the third one will be [2], etc
  - return: `array` - Each row execute the calculation and store it in another array which is returned at the end. This new value simulates a new column creation with modified values
 - `date_format` - format dates based on PHP (date_format)[https://www.php.net/manual/en/datetime.format.php] function
  - parameters: `dates` a date or comma separated dates, `format` - the format that we will use
 - `wrap_for_globals` - Wrap string or comma separated string (each item) in {{}}
  - parameters: `string` - the string that we want to wrap
 - `set_globals` - Search for global keywords and replace them
  - parameters: The string that we will search for globals 
 - `get_current_date` - Get the current date
  - parameters: `format` - the date format of the current date, ex: `Y-m-d`, `d-m-Y`, `Y-m-d H:i`
 - `count` (for array) - pass array of values and return the length of the array
  - parameters: array with values
  - examples: `[
                    {
                        "formula": "count(array($arr))",
                        "result_holder": "arr1_length",
                        "variables": {
                            "arr": "{{record_id}}"
                        }
                    }
                ]`
- `array_filter_by_value` filter array by value. 
 - parameters: `array` with values and `filter_value`
 - examples: `[
	{
		"formula": "array_filter_by_value(['1','2','1', '3'],$filtered_value)",
		"result_holder": "filtered_array",
		"variables": {			
			"filtered_value": "1"
		}
	}
]`


# Installation

 - Download the code into the `plugin` folder
 - Checkout the latest version 
 - Execute all `.sql` script in the DB folder in their version order

# Requirements

 - SelfHelp v5.2.5+
 - PHP 7.4+ better use 8.1
