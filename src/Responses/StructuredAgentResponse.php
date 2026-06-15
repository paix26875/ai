<?php

namespace Laravel\Ai\Responses;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class StructuredAgentResponse extends AgentResponse implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    use ProvidesStructuredResponse;

    /**
     * Schema constraint violations that remained after the configured retries.
     *
     * Empty when the output satisfied the schema, or when "ai.structured_output.on_failure"
     * is set to "throw" (in which case a StructuredOutputValidationException is raised instead).
     *
     * @var list<string>
     */
    public array $validationErrors = [];

    public function __construct(string $invocationId, array $structured, string $text, Usage $usage, Meta $meta)
    {
        parent::__construct($invocationId, $text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
    }

    /**
     * Attach the schema validation errors that remained after retries.
     *
     * @param  list<string>  $errors
     */
    public function withValidationErrors(array $errors): self
    {
        $this->validationErrors = $errors;

        return $this;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->structured;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->structured, $options);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the string representation of the object.
     */
    public function __toString(): string
    {
        return json_encode($this->structured);
    }
}
