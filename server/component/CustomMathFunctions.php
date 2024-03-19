<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../ext/php-math/vendor/autoload.php";
require_once __DIR__ . "/../ext/math-executor/vendor/autoload.php";

use NXP\MathExecutor;
use MathPHP\Probability\Distribution\Continuous;
use MathPHP\Statistics\Descriptive;

class CustomMathFunctions
{

    /**
     * The math executor instance
     */
    private $executor;

    /**
     * An associative array holding the different available services. See the
     * class definition basepage for a list of all services.
     */
    protected $services;

    /**
     * The constructor creates an instance of the CustomMathFunctions.
     */
    public function __construct($services)
    {
        $this->services = $services;
        $this->executor = new MathExecutor();
        $this->set_math_functions();
        $this->executor->setVarValidationHandler(function (string $name, $variable) {
            // allow all scalars, array and null
            if (is_numeric($variable) || is_array($variable) || is_string($variable)) {
                return;
            }
            throw new Exception("Invalid variable type");
        });
    }

    /* Private Methods *********************************************************/

    private function set_function_sum()
    {
        //Array sum function
        $this->executor->addFunction('sum', function ($arg1, ...$args) {
            if (\is_array($arg1)) {
                if (0 === \count($arg1)) {
                    throw new \InvalidArgumentException('Array must contain at least one element!');
                }
                return \array_sum($arg1);
            }

            $args = [$arg1, ...$args];

            return \array_sum($args);
        });
    }

    private function set_function_count()
    {
        //Array count function
        $this->executor->addFunction('count', function ($arg1, ...$args) {
            if (\is_array($arg1)) {
                return \count($arg1);
            }

            $args = [$arg1, ...$args];

            return \count($args);
        });
    }

    private function set_function_order_array()
    {
        //Array order function
        $this->executor->addFunction('order_array', function ($arr, $key, $sort_type) {
            try {
                if (!is_array($arr)) {
                    return array("error" => 'First parameter is not array');
                } else if (!($sort_type == "SORT_ASC" || $sort_type == "SORT_DESC")) {
                    return array("error" => 'Third parameter is not SORT_ASC or SORT_DESC');
                } else {
                    $arr_column = array_column($arr, $key);
                    if (count($arr_column) != count($arr)) {
                        return array("error" => 'Second parameter is not correct');
                    }
                    $sort_type = $sort_type == "SORT_DESC" ? SORT_DESC : SORT_ASC;
                    array_multisort($arr_column, $sort_type, $arr);
                    return $arr;
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_array_filter_by_value()
    {
        //Array order function
        $this->executor->addFunction('array_filter_by_value', function ($arr, $filterValue) {
            try {
                if (!is_array($arr)) {
                    return array("error" => 'First parameter is not array');
                } else if (!isset($filterValue)) {
                    return array("error" => 'filtered value is not set');
                } else {
                    // Use array_filter with an inline callback function
                    $resultArray = array_filter($arr, function ($value) use ($filterValue) {
                        return $value === $filterValue;
                    });
                    return array_values($resultArray);
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_normal_cdf()
    {
        // CDF - Normal distribution
        $this->executor->addFunction('normal_cdf', function ($x, $mu, $sigma) {
            try {
                if (is_numeric($x) && is_numeric($mu) && is_numeric($sigma)) {
                    $normal = new Continuous\Normal($mu, $sigma);
                    return $normal->cdf($x);
                } else {
                    return array("error" => 'Some of the passed parameters are not numeric');
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_standard_deviation()
    {
        // Standard deviation (For a sample; uses sample variance)
        $this->executor->addFunction('standard_deviation', function ($values) {
            try {
                return Descriptive::standardDeviation($values, true);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_re_rank()
    {
        /**
         * Re rank values based on a table
         * @param array $table
         * array (the table) with objects (the row)
         * @param number $value 
         * Current value
         * @param string $key
         * The key that we are looking for the new value
         * @return any
         * Return the new value
         */
        $this->executor->addFunction('re_rank', function ($table, $value, $key) {
            try {
                $result = null;
                foreach ($table as $t_key => $t_value) {
                    if (!is_numeric($value) || !is_numeric($t_key)) {
                        return array("error" => $value . ' or ' . $t_key . ' is not numeric');
                    } else {
                        if ($value >= $t_key) {
                            if (isset($t_value[$key])) {
                                $result = $t_value[$key];
                            } else {
                                return array("error" => 'Wrong key');
                            }
                        }
                    }
                }
                return $result;
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_calc_time_diff()
    {
        /**
         * Calculate difference between 2 dates and returns hours
         * @param string $time1 
         * first data
         * @param string $time2
         * second date
         * @param string $unit
         * The type of the return value (seconds, minutes, hours, etc)
         * @return double 
         * Return the hours between both dates
         */
        $this->executor->addFunction('calc_time_diff', function ($time1, $time2, $unit) {
            try {
                $unit_val = 3600;
                switch ($unit) {
                    case FP_SECONDS:
                        $unit_val =  1;
                        break;
                    case FP_MINUTES:
                        $unit_val =  60;
                        break;
                    case FP_HOURS:
                        $unit_val =  60 * 60;
                        break;
                    case FP_DAYS:
                        $unit_val =  60 * 60 * 24;
                        break;
                    case FP_WEEKS:
                        $unit_val =  60 * 60 * 24 * 7;
                        break;
                    case FP_MONTHS:
                        $unit_val =  60 * 60 * 24 * 30;
                        break;
                    case FP_YEARS:
                        $unit_val =  60 * 60 * 24 * 365;
                        break;

                    default:
                        $unit_val = 3600; //default is hours
                        break;
                }

                $diff = (strtotime($time2) - strtotime($time1)) / $unit_val;
                return $diff;
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_explode()
    {
        $this->executor->addFunction('explode', function ($separator, $string) {
            try {
                return explode($separator, $string);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    private function set_function_calculations_on_rows()
    {
        /**
         * Execute calculations on rows. The required parameters are column arrays. These arrays has the same length and they are looped in the same cycle. 
         * This give us the option to take the same row value and manipulate it and create a new column for example.
         * @param array $arrays
         * Send array which contain all required columns
         * @param string $formula
         * The formula that we want to use. The passed arrays are looped and they are in the same loop cycle, which give us the row values. The columns can be used with [number],
         * The first column will be [0], the second will be [1], the third one will be [2], etc
         * @return array
         * Each row execute the calculation and store it in another array which is returned at the end. This new value simulates a new column creation with modified values
         */
        $this->executor->addFunction('calculations_on_rows', function ($arrays, $formula) {
            try {
                $res = array();
                preg_match_all("/\[[^\]]*\]/", $formula, $matches); // find all variables between []
                foreach ($arrays[0] as $key => $value) {
                    $ex_formula = $formula;
                    foreach ($matches[0] as $match_key => $match) {
                        $ex_formula = str_replace($match, $arrays[str_replace('[', '', str_replace(']', '', $match))][$key], $ex_formula);
                    }
                    $res[] =  $this->executor->execute($ex_formula);
                }
                return $res;
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Format date based on php format function
     * @param string $dates
     * a date or comma separated dates
     * @param string format
     * the format that we will use
     * @return string
     * Return the formatted string
     */
    private function set_function_date_format()
    {
        $this->executor->addFunction('date_format', function ($date, $format) {
            try {
                $res = array();
                $return_arr = false;
                if (is_array($date)) {
                    $dates = $date;
                    $return_arr = true;
                } else {
                    $dates = explode(',', $date);
                }
                foreach ($dates as $key => $value) {
                    $date_obj = date_create($value);
                    $res[] = date_format($date_obj, $format);
                }
                if ($return_arr) {
                    return "[" . implode(',', array_map(function ($item) {
                        return '"' . $item . '"';
                    }, $res)) . "]";
                }
                return implode(',', $res);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Wrap string or comma separated string (each item) in {{}}. 
     * @param string string
     * the string that we want to wrap
     * @return string 
     * Return the wrapped string
     */
    private function set_function_wrap_for_globals()
    {
        $this->executor->addFunction('wrap_for_globals', function ($string) {
            try {
                $res = array();
                $string = explode(',', $string);
                foreach ($string as $key => $value) {
                    $res[] = '{{' . $value . '}}';
                }
                return implode(',', $res);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Search for global keywords and replace them
     * @param string $string
     * The string that we will search for globals
     * @return string
     * Return the converted string
     */
    private function set_function_set_globals()
    {
        $this->executor->addFunction('set_globals', function ($string) {
            try {
                $globals = $this->services->get_db()->get_global_values();
                return $this->services->get_db()->replace_calced_values($string, $globals);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Implode array into string, if a wrap elements is defined it will wrap the items with the given element
     * @param array $array
     * The array which will be imploded
     * @param string $separator
     * The separator string that will be used, by default it is comma (,)
     * @param string $wrap_element
     * the element that will be used to wrap the items, by default is none
     * @return string
     * Return the converted array as a string
     */
    private function set_function_implode()
    {
        $this->executor->addFunction('implode', function ($array, $separator = ',', $wrap_element = '') {
            try {
                return $wrap_element . implode($wrap_element . $separator . $wrap_element, $array) . $wrap_element;
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Get the current date in the desired format
     * @param string format
     * The date format
     * @return string
     * Return the current date formatted as a string
     */
    private function set_function_get_current_date()
    {
        $this->executor->addFunction('get_current_date', function ($format) {
            try {
                return date($format);
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Modifies a single date based on the provided format, modification, and optionally a new format.
     * 
     * @param string $dateString The date string to modify.
     * @param string $current_format The format of the input date string.
     * @param string $modification The modification to apply to the date (e.g., +1 day, -1 hour).
     * @param string|null $new_format Optional. The format for the modified date string. If not provided, the original format is used.
     * 
     * @return string|array Returns the modified date string or an array containing an error message if parsing fails.
     */
    private function modifySingleDate($dateString, $current_format, $modification, $new_format)
    {
        $dateTime = DateTime::createFromFormat($current_format, $dateString);

        if (!$dateTime) {
            return array("error" => "Invalid date string: $dateString");
        }

        $dateTime->modify($modification);

        return $dateTime->format($new_format ? $new_format : $current_format);
    }

    /**
     * Sets up the 'modify_date' function for execution.
     * 
     * This function adds the 'modify_date' function to the executor, which can modify a single date or an array of dates
     * based on the provided format, modification, and optionally a new format. It relies on the 'modifySingleDate' 
     * function for handling individual date modifications.
     * 
     * @return void
     */
    private function set_function_modify_date()
    {
        $this->executor->addFunction('modify_date', function ($date, $current_format, $modification, $new_format = null) {
            try {
                if (is_array($date)) {
                    $modifiedDates = [];
                    foreach ($date as $singleDate) {
                        $modifiedDates[] = $this->modifySingleDate($singleDate, $current_format, $modification, $new_format);
                    }
                    return $modifiedDates;
                } else {
                    return $this->modifySingleDate($date, $current_format, $modification, $new_format);
                }
            } catch (Exception $e) {
                return array("error" => $e->getMessage());
            }
        });
    }

    /**
     * Set all custom functions that we want to add to the math executor
     */
    private function set_math_functions()
    {
        $this->set_function_calc_time_diff();
        $this->set_function_calculations_on_rows();
        $this->set_function_explode();
        $this->set_function_normal_cdf();
        $this->set_function_order_array();
        $this->set_function_re_rank();
        $this->set_function_standard_deviation();
        $this->set_function_sum();
        $this->set_function_date_format();
        $this->set_function_wrap_for_globals();
        $this->set_function_set_globals();
        $this->set_function_implode();
        $this->set_function_get_current_date();
        $this->set_function_count();
        $this->set_function_array_filter_by_value();
        $this->set_function_modify_date();
    }

    /* Public Methods *********************************************************/

    /**
     * Get the mathematical executor
     */
    public function get_executor()
    {
        return $this->executor;
    }
}
?>
