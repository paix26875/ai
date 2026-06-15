<?php

namespace Laravel\Ai\Schema;

class StructuredOutputNormalizer
{
    /**
     * Recursively remove keywords a provider's native structured output cannot represent.
     *
     * Some providers (e.g. Anthropic's output_config.format) compile the schema against a
     * strict JSON Schema subset and reject validation keywords such as minimum/maximum with
     * a 400 error. The keyword is removed from the outgoing schema, but it is folded into the
     * node's "description" as a natural-language note so the model still honors the constraint
     * on its first attempt. Conformance is verified after the response is parsed.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $unsupported
     * @return array<string, mixed>
     */
    public static function strip(array $schema, array $unsupported): array
    {
        $notes = [];

        foreach ($unsupported as $keyword) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }

            if (($note = self::describe($keyword, $schema[$keyword])) !== null) {
                $notes[] = $note;
            }

            unset($schema[$keyword]);
        }

        if ($notes !== []) {
            $schema['description'] = self::appendNotes($schema['description'] ?? null, $notes);
        }

        if (is_array($schema['properties'] ?? null)) {
            foreach ($schema['properties'] as $key => $definition) {
                if (is_array($definition)) {
                    $schema['properties'][$key] = self::strip($definition, $unsupported);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = self::strip($schema['items'], $unsupported);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $branch) {
            if (is_array($schema[$branch] ?? null)) {
                $schema[$branch] = array_map(
                    fn ($node) => is_array($node) ? self::strip($node, $unsupported) : $node,
                    $schema[$branch],
                );
            }
        }

        return $schema;
    }

    /**
     * Render a removed constraint keyword as a natural-language note, or null to omit it.
     */
    private static function describe(string $keyword, mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        return match ($keyword) {
            'minimum' => "minimum {$value}",
            'maximum' => "maximum {$value}",
            'exclusiveMinimum' => "greater than {$value}",
            'exclusiveMaximum' => "less than {$value}",
            'multipleOf' => "a multiple of {$value}",
            'minLength' => "at least {$value} characters",
            'maxLength' => "at most {$value} characters",
            'minItems' => "at least {$value} items",
            'maxItems' => "at most {$value} items",
            'uniqueItems' => $value ? 'unique items' : null,
            'minProperties' => "at least {$value} properties",
            'maxProperties' => "at most {$value} properties",
            default => null,
        };
    }

    /**
     * Append constraint notes to an existing description string.
     *
     * @param  list<string>  $notes
     */
    private static function appendNotes(mixed $description, array $notes): string
    {
        $sentence = 'Constraints: '.implode(', ', $notes).'.';

        return is_string($description) && $description !== ''
            ? rtrim($description).' '.$sentence
            : $sentence;
    }
}
