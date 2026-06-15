<?php

namespace Laravel\Ai\Exceptions;

class StructuredOutputValidationException extends AiException
{
    /**
     * The validation errors describing how the output violated the schema.
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * The last structured output the model returned.
     *
     * @var array<string, mixed>
     */
    public array $structured = [];

    /**
     * Create a new exception for output that failed schema validation after all retries.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $structured
     */
    public static function withErrors(array $errors, array $structured, int $attempts): self
    {
        $exception = new self(sprintf(
            'Structured output did not satisfy the schema after %d %s: %s',
            $attempts,
            $attempts === 1 ? 'attempt' : 'attempts',
            implode(' ', $errors),
        ));

        $exception->errors = $errors;
        $exception->structured = $structured;

        return $exception;
    }
}
