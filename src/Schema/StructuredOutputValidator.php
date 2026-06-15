<?php

namespace Laravel\Ai\Schema;

class StructuredOutputValidator
{
    /**
     * Validate parsed structured output against the schema's value constraints.
     *
     * Returns a list of human-readable error messages; an empty list means the data conforms.
     *
     * This is the provider-agnostic guarantee layer. It intentionally checks only the
     * constraint keywords a provider's native structured output may not be able to enforce
     * (numeric ranges, string lengths, item counts, uniqueness). The structural contract —
     * types, required properties, enums — is guaranteed by the provider's structured output
     * and is therefore not re-validated here, keeping the check purely additive.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function validate(array $schema, mixed $data, string $path = ''): array
    {
        return (new self)->node($schema, $data, $path === '' ? 'value' : $path);
    }

    /**
     * Validate the constraints on a single schema node against a value.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function node(array $schema, mixed $data, string $path): array
    {
        return match (true) {
            is_int($data), is_float($data) => $this->numeric($schema, $data, $path),
            is_string($data) => $this->string($schema, $data, $path),
            is_array($data) && array_is_list($data) => $this->array($schema, $data, $path),
            is_array($data) => $this->object($schema, $data, $path),
            default => [],
        };
    }

    /**
     * Validate numeric constraints.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function numeric(array $schema, int|float $data, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = sprintf('%s: must be >= %s, got %s.', $path, $schema['minimum'], $this->stringify($data));
        }

        if (isset($schema['maximum']) && $data > $schema['maximum']) {
            $errors[] = sprintf('%s: must be <= %s, got %s.', $path, $schema['maximum'], $this->stringify($data));
        }

        if (isset($schema['exclusiveMinimum']) && $data <= $schema['exclusiveMinimum']) {
            $errors[] = sprintf('%s: must be > %s, got %s.', $path, $schema['exclusiveMinimum'], $this->stringify($data));
        }

        if (isset($schema['exclusiveMaximum']) && $data >= $schema['exclusiveMaximum']) {
            $errors[] = sprintf('%s: must be < %s, got %s.', $path, $schema['exclusiveMaximum'], $this->stringify($data));
        }

        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && $schema['multipleOf'] != 0) {
            $quotient = $data / $schema['multipleOf'];

            if (abs($quotient - round($quotient)) > 1e-9) {
                $errors[] = sprintf('%s: must be a multiple of %s, got %s.', $path, $schema['multipleOf'], $this->stringify($data));
            }
        }

        return $errors;
    }

    /**
     * Validate string constraints.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function string(array $schema, string $data, string $path): array
    {
        $errors = [];
        $length = mb_strlen($data);

        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = sprintf('%s: must be at least %s characters, got %s.', $path, $schema['minLength'], $length);
        }

        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = sprintf('%s: must be at most %s characters, got %s.', $path, $schema['maxLength'], $length);
        }

        return $errors;
    }

    /**
     * Validate array constraints and recurse into item schemas.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, mixed>  $data
     * @return list<string>
     */
    private function array(array $schema, array $data, string $path): array
    {
        $errors = [];
        $count = count($data);

        if (isset($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = sprintf('%s: must have at least %s items, got %s.', $path, $schema['minItems'], $count);
        }

        if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = sprintf('%s: must have at most %s items, got %s.', $path, $schema['maxItems'], $count);
        }

        if (($schema['uniqueItems'] ?? false) === true && $count !== count(array_unique(array_map($this->stringify(...), $data)))) {
            $errors[] = sprintf('%s: must have unique items.', $path);
        }

        if (is_array($schema['items'] ?? null)) {
            foreach ($data as $index => $item) {
                $errors = [...$errors, ...$this->node($schema['items'], $item, "{$path}[{$index}]")];
            }
        }

        return $errors;
    }

    /**
     * Recurse into the constraints of an object's known properties.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function object(array $schema, array $data, string $path): array
    {
        $errors = [];

        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition) && array_key_exists($key, $data)) {
                    $errors = [...$errors, ...$this->node($definition, $data[$key], $path === 'value' ? $key : "{$path}.{$key}")];
                }
            }
        }

        return $errors;
    }

    /**
     * Render a scalar value for an error message.
     */
    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => json_encode($value),
        };
    }
}
