<?php

declare(strict_types=1);

namespace Donate\utils;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function strtolower;

/**
 * Utility class for safely handling data type conversions
 */
final class DataTypeUtils {
	/**
	 * Safely convert a value to integer
	 *
	 * @param mixed $value   The value to convert
	 * @param int   $default Default value to return if conversion is not possible
	 * @return int The converted value or default if conversion is not possible
	 */
	public static function toInt(mixed $value, int $default = 0) : int {
		if (is_int($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return (int) $value;
		}

		return $default;
	}

	/**
	 * Safely convert a value to string
	 *
	 * @param mixed  $value   The value to convert
	 * @param string $default Default value to return if conversion is not possible
	 * @return string The converted value or default if conversion is not possible
	 */
	public static function toString(mixed $value, string $default = "") : string {
		if (is_string($value)) {
			return $value;
		}

		if (is_scalar($value)) {
			return (string) $value;
		}

		return $default;
	}

	/**
	 * Safely convert a value to float
	 *
	 * @param mixed $value   The value to convert
	 * @param float $default Default value to return if conversion is not possible
	 * @return float The converted value or default if conversion is not possible
	 */
	public static function toFloat(mixed $value, float $default = 0.0) : float {
		if (is_float($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return (float) $value;
		}

		return $default;
	}

	/**
	 * Safely convert a value to boolean
	 *
	 * @param mixed $value   The value to convert
	 * @param bool  $default Default value to return if conversion is not possible
	 * @return bool The converted value or default if conversion is not possible
	 */
	public static function toBool(mixed $value, bool $default = false) : bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			$lower = strtolower($value);
			if ($lower === "true" || $lower === "1" || $lower === "yes" || $lower === "y") {
				return true;
			}
			if ($lower === "false" || $lower === "0" || $lower === "no" || $lower === "n") {
				return false;
			}
		}

		if (is_numeric($value)) {
			return (bool) (int) $value;
		}

		return $default;
	}

	/**
	 * Safely get a value from an array by key
	 *
	 * @param array<mixed> $array   The array to get a value from
	 * @param string|int   $key     The key to look for
	 * @param mixed        $default Default value to return if key does not exist
	 * @return mixed The value or default if key does not exist
	 */
	public static function getArrayValue(array $array, string|int $key, mixed $default = null) : mixed {
		return $array[$key] ?? $default;
	}

	/**
	 * Check if a value is an array and not empty
	 *
	 * @param mixed $value The value to check
	 * @return bool True if the value is a non-empty array, false otherwise
	 */
	public static function isNonEmptyArray(mixed $value) : bool {
		return is_array($value) && !empty($value);
	}

	/**
	 * Safely convert an array of values to integers
	 *
	 * @param array<mixed> $values The array of values to convert
	 * @return array<int> The array of converted values
	 */
	public static function toIntArray(array $values) : array {
		$result = [];
		foreach ($values as $key => $value) {
			$result[$key] = self::toInt($value);
		}
		return $result;
	}

	/**
	 * Safely convert an array of values to strings
	 *
	 * @param array<mixed> $values The array of values to convert
	 * @return array<string> The array of converted values
	 */
	public static function toStringArray(array $values) : array {
		$result = [];
		foreach ($values as $key => $value) {
			$result[$key] = self::toString($value);
		}
		return $result;
	}

	/**
	 * Safely convert an array to array<string, mixed> by ensuring all keys are strings
	 *
	 * @param array<mixed, mixed> $data Input array with any key types
	 * @return array<string, mixed> Array with string keys
	 */
	public static function toStringKeyedArray(array $data) : array {
		$result = [];
		foreach ($data as $key => $value) {
			$result[self::toString($key)] = $value;
		}
		return $result;
	}
}
