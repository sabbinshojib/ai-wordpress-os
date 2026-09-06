<?php
/**
 * JSON-Schema-subset input validator.
 *
 * Tool input schemas use a practical subset of JSON Schema:
 *
 *   type            (string | array of strings)
 *   required        (array of property names)
 *   properties      (map of property → subschema)
 *   enum            (allowed values)
 *   minimum/maximum (numbers)
 *   minLength/maxLength, pattern (strings)
 *   minItems/maxItems, items (arrays)
 *   additionalProperties (bool)
 *   default         (accepted, ignored for validation)
 *   description     (accepted, ignored for validation)
 *   anyOf / oneOf   (first-match validation, no overlap check)
 *
 * Unknown keywords are ignored so richer schemas still validate.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Validator {

        /**
         * Validate a value against a schema subset.
         *
         * @param mixed              $value  Candidate value (already JSON-decoded).
         * @param array<string,mixed> $schema JSON-Schema subset.
         * @param string             $path   JSON pointer-ish path for messages.
         * @return string[]  List of human-readable violations. Empty = valid.
         */
        public function validate( mixed $value, array $schema, string $path = 'input' ): array {
                $errors = array();

                if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
                        return $this->validateAnyOf( $value, $schema['anyOf'], $path );
                }
                if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
                        return $this->validateAnyOf( $value, $schema['oneOf'], $path );
                }

                $type = $schema['type'] ?? null;
                if ( null !== $type ) {
                        $type_errors = $this->checkType( $value, $type, $path );
                        if ( ! empty( $type_errors ) ) {
                                return $type_errors; // Type mismatch short-circuits further checks.
                        }
                }

                $errors = array_merge( $errors, $this->checkEnum( $value, $schema, $path ) );
                $errors = array_merge( $errors, $this->checkNumeric( $value, $schema, $path ) );
                $errors = array_merge( $errors, $this->checkString( $value, $schema, $path ) );

                if ( is_array( $value ) && array_is_list( $value ) ) {
                        $errors = array_merge( $errors, $this->checkArray( $value, $schema, $path ) );
                }

                // Empty arrays are ambiguous (JSON {} decodes to []): run object
                // checks for them too so `required` still applies.
                if ( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ) ) {
                        $errors = array_merge( $errors, $this->checkObject( $value, $schema, $path ) );
                }

                return $errors;
        }

        /**
         * Validate the full "arguments" object of a tool call.
         *
         * @param array<string,mixed> $arguments
         * @param array<string,mixed> $schema    The tool input schema (an object schema).
         * @return string[] Violations, empty when valid.
         */
        public function validateArguments( array $arguments, array $schema ): array {
                return $this->validate( $arguments, $schema, 'arguments' );
        }

        /**
         * @param array<int, array<string,mixed>> $branches
         * @return string[]
         */
        private function validateAnyOf( mixed $value, array $branches, string $path ): array {
                foreach ( $branches as $branch ) {
                        if ( empty( $this->validate( $value, $branch, $path ) ) ) {
                                return array();
                        }
                }
                return array( sprintf( '%s must match one of the allowed schemas (anyOf/oneOf).', $path ) );
        }

        /**
         * @param string|string[] $type
         * @return string[]
         */
        private function checkType( mixed $value, $type, string $path ): array {
                $allowed = is_array( $type ) ? $type : array( $type );

                foreach ( $allowed as $candidate ) {
                        if ( $this->matchesType( $value, (string) $candidate ) ) {
                                return array();
                        }
                }

                return array( sprintf( '%s must be of type %s.', $path, implode( '|', $allowed ) ) );
        }

        private function matchesType( mixed $value, string $type ): bool {
                return match ( $type ) {
                        // An empty PHP array is ambiguous (JSON {} and [] both decode
                        // to []), so it satisfies both object and array checks.
                        'object'  => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
                        'array'   => is_array( $value ) && ( array() === $value || array_is_list( $value ) ),
                        'string'  => is_string( $value ),
                        'integer' => is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value ),
                        'number'  => is_int( $value ) || is_float( $value ),
                        'boolean' => is_bool( $value ),
                        'null'    => null === $value,
                        default   => true, // Unknown type keyword: be permissive, schema author error.
                };
        }

        /**
         * @param array<string,mixed> $schema
         * @return string[]
         */
        private function checkEnum( mixed $value, array $schema, string $path ): array {
                if ( ! isset( $schema['enum'] ) || ! is_array( $schema['enum'] ) ) {
                        return array();
                }
                foreach ( $schema['enum'] as $allowed ) {
                        // Loose equality on purpose: JSON enums are scalars.
                        /* phpcs:ignore Universal.Operators.StrictComparisons.LooseStrict */
                        if ( $value == $allowed && gettype( $value ) === gettype( $allowed ) ) {
                                return array();
                        }
                }
                return array( sprintf( '%s must be one of: %s.', $path, implode( ', ', array_map( 'strval', $schema['enum'] ) ) ) );
        }

        /**
         * @param array<string,mixed> $schema
         * @return string[]
         */
        private function checkNumeric( mixed $value, array $schema, string $path ): array {
                if ( ! is_int( $value ) && ! is_float( $value ) ) {
                        return array();
                }
                $errors = array();
                if ( isset( $schema['minimum'] ) && is_numeric( $schema['minimum'] ) && $value < $schema['minimum'] ) {
                        $errors[] = sprintf( '%s must be >= %s.', $path, (string) $schema['minimum'] );
                }
                if ( isset( $schema['maximum'] ) && is_numeric( $schema['maximum'] ) && $value > $schema['maximum'] ) {
                        $errors[] = sprintf( '%s must be <= %s.', $path, (string) $schema['maximum'] );
                }
                return $errors;
        }

        /**
         * @param array<string,mixed> $schema
         * @return string[]
         */
        private function checkString( mixed $value, array $schema, string $path ): array {
                if ( ! is_string( $value ) ) {
                        return array();
                }
                $errors = array();
                if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < (int) $schema['minLength'] ) {
                        $errors[] = sprintf( '%s must be at least %d characters.', $path, (int) $schema['minLength'] );
                }
                if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > (int) $schema['maxLength'] ) {
                        $errors[] = sprintf( '%s must be at most %d characters.', $path, (int) $schema['maxLength'] );
                }
                if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
                        $result = @preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value );
                        if ( 0 === $result ) {
                                $errors[] = sprintf( '%s does not match the required pattern.', $path );
                        }
                }
                return $errors;
        }

        /**
         * @param array<int, mixed>   $value
         * @param array<string,mixed> $schema
         * @return string[]
         */
        private function checkArray( array $value, array $schema, string $path ): array {
                $errors = array();
                $count  = count( $value );

                if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
                        $errors[] = sprintf( '%s must contain at least %d items.', $path, (int) $schema['minItems'] );
                }
                if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
                        $errors[] = sprintf( '%s must contain at most %d items.', $path, (int) $schema['maxItems'] );
                }
                if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
                        foreach ( $value as $index => $item ) {
                                $item_errors = $this->validate( $item, $schema['items'], sprintf( '%s[%d]', $path, $index ) );
                                $errors      = array_merge( $errors, $item_errors );
                        }
                }
                return $errors;
        }

        /**
         * @param array<string, mixed> $value
         * @param array<string, mixed> $schema
         * @return string[]
         */
        private function checkObject( array $value, array $schema, string $path ): array {
                $errors = array();

                if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
                        foreach ( $schema['required'] as $required_property ) {
                                if ( ! array_key_exists( (string) $required_property, $value ) ) {
                                        $errors[] = sprintf( '%s.%s is required.', $path, (string) $required_property );
                                }
                        }
                }

                $properties = ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) ? $schema['properties'] : array();

                foreach ( $value as $key => $item ) {
                        if ( isset( $properties[ (string) $key ] ) && is_array( $properties[ (string) $key ] ) ) {
                                $errors = array_merge( $errors, $this->validate( $item, $properties[ (string) $key ], $path . '.' . (string) $key ) );
                        } elseif ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] && ! isset( $properties[ (string) $key ] ) ) {
                                $errors[] = sprintf( '%s.%s is not an allowed property.', $path, (string) $key );
                        }
                }

                return $errors;
        }
}
