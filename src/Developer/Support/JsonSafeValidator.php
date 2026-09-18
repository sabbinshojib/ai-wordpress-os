<?php
/**
 * Validator and canonicalizer for JSON-safe data structures.
 *
 * Ensures data structures (metadata, plan parameters, etc.) contain
 * only deterministic, JSON-serializable primitives (strings, ints,
 * non-NaN/INF floats, bools, nulls, arrays). Rejects objects,
 * resources, closures, and callables.
 *
 * @package AIOS\Developer\Support
 */

declare( strict_types=1 );

namespace AIOS\Developer\Support;

final class JsonSafeValidator {

	/**
	 * Recursively assert that the provided value is strictly JSON-safe.
	 *
	 * @param mixed  $value The value to check.
	 * @param string $path  The field path for error reporting.
	 * @throws \InvalidArgumentException If an invalid type or value is encountered.
	 */
	public static function assertJsonSafe( mixed $value, string $path = 'root' ): void {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return;
		}

		if ( is_float( $value ) ) {
			if ( is_nan( $value ) || is_infinite( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'Non-finite float (NaN/Inf) at %s is not JSON-safe.', $path ) );
			}
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $sub_value ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) {
					throw new \InvalidArgumentException( sprintf( 'Array key at %s must be string or integer.', $path ) );
				}
				self::assertJsonSafe( $sub_value, $path . '.' . (string) $key );
			}
			return;
		}

		throw new \InvalidArgumentException(
			sprintf(
				'Value at %s has unsupported type %s — only JSON-safe primitives and arrays are accepted.',
				$path,
				gettype( $value )
			)
		);
	}

	/**
	 * Canonicalize an array by sorting associative keys recursively
	 * while strictly preserving sequential indexed array order.
	 *
	 * @param mixed $data The data structure to canonicalize.
	 * @return mixed The canonicalized data.
	 */
	public static function canonicalize( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_assoc = false;
		$i        = 0;
		foreach ( $data as $k => $v ) {
			if ( $k !== $i ) {
				$is_assoc = true;
				break;
			}
			++$i;
		}

		$result = array();
		if ( $is_assoc ) {
			$keys = array_keys( $data );
			sort( $keys, SORT_STRING );
			foreach ( $keys as $k ) {
				$result[ $k ] = self::canonicalize( $data[ $k ] );
			}
		} else {
			foreach ( $data as $v ) {
				$result[] = self::canonicalize( $v );
			}
		}

		return $result;
	}
}
