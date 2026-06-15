<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\StructuredOutputValidationException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Schema\StructuredOutputValidator;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;

use function Laravel\Ai\pipeline;

trait GeneratesText
{
    protected string $currentToolInvocationId;

    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $processedPrompt = null;

        $response = pipeline()
            ->send($prompt)
            ->through($this->gatherMiddlewareFor($prompt->agent))
            ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt) {
                $processedPrompt = $prompt;

                $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

                $agent = $prompt->agent;

                $messages = [
                    ...($agent instanceof Conversational ? $agent->messages() : []),
                    new UserMessage($prompt->prompt, $prompt->attachments->all()),
                ];

                $this->listenForToolInvocations($invocationId, $agent);

                $schema = $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null;

                if (! empty($schema)) {
                    [$response, $errors] = $this->generateValidatedStructuredText($agent, $prompt, $messages, $schema);

                    if ($errors !== [] && config('ai.structured_output.on_failure', 'throw') === 'throw') {
                        throw StructuredOutputValidationException::withErrors(
                            $errors,
                            $response->structured,
                            (int) config('ai.structured_output.max_retries', 2) + 1,
                        );
                    }

                    return (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                        ->withValidationErrors($errors)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps);
                }

                $response = $this->textGateway()->generateText(
                    $this,
                    $prompt->model,
                    (string) $agent->instructions(),
                    $messages,
                    $this->resolveTools($agent),
                    $schema,
                    TextGenerationOptions::forAgent($agent),
                    $prompt->timeout,
                );

                return (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                    ->withMessages($response->messages)
                    ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                    ->withSteps($response->steps);
            });

        $this->events->dispatch(
            new AgentPrompted($invocationId, $processedPrompt ?? $prompt, $response)
        );

        return $response;
    }

    /**
     * Generate a structured response, re-prompting until it satisfies the schema or retries run out.
     *
     * @param  array<string, Type>  $schema
     * @param  array<int, Message>  $messages
     * @return array{0: TextResponse, 1: list<string>}
     */
    protected function generateValidatedStructuredText(Agent $agent, AgentPrompt $prompt, array $messages, array $schema): array
    {
        $fullSchema = (new ObjectSchema($schema))->toSchema();
        $tools = $this->resolveTools($agent);
        $options = TextGenerationOptions::forAgent($agent);
        $maxRetries = max(0, (int) config('ai.structured_output.max_retries', 2));

        $errors = [];
        $response = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $messages[] = new UserMessage($this->structuredRetryPrompt($response->structured, $errors));
            }

            $response = $this->textGateway()->generateText(
                $this,
                $prompt->model,
                (string) $agent->instructions(),
                $messages,
                $tools,
                $schema,
                $options,
                $prompt->timeout,
            );

            $errors = StructuredOutputValidator::validate($fullSchema, $response->structured);

            if ($errors === []) {
                break;
            }
        }

        return [$response, $errors];
    }

    /**
     * Build the corrective prompt sent when the previous structured output violated the schema.
     *
     * @param  array<string, mixed>  $previous
     * @param  list<string>  $errors
     */
    protected function structuredRetryPrompt(array $previous, array $errors): string
    {
        return implode("\n", [
            'Your previous response did not satisfy the required output schema:',
            ...array_map(fn ($error) => '- '.$error, $errors),
            '',
            'Previous response: '.json_encode($previous),
            '',
            'Return a corrected response that satisfies every constraint listed above.',
        ]);
    }

    /**
     * Gather the middleware for the given agent.
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = Ai::hasFakeGatewayFor($agent::class) ? [function (AgentPrompt $prompt, Closure $next) {
            Ai::recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        if (in_array(RemembersConversations::class, class_uses_recursive($agent))
            && $agent->hasConversationParticipant()) {
            $middleware[] = new RememberConversation(resolve(ConversationStore::class), $this);
        }

        return $agent instanceof HasMiddleware
            ? [...$middleware, ...$agent->middleware()]
            : $middleware;
    }

    /**
     * Resolve the tools for the given agent, wrapping any agent instances as tools.
     */
    protected function resolveTools(Agent $agent): array
    {
        if (! $agent instanceof HasTools) {
            return [];
        }

        return array_map(
            fn ($tool) => $this->resolveTool($tool),
            [...$agent->tools()],
        );
    }

    /**
     * Resolve a tool returned by the agent into a native tool instance when needed.
     */
    protected function resolveTool(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    /**
     * Listen for gateway tool invocations and dispatch events for the given agent when the tools are invoked.
     */
    protected function listenForToolInvocations(string $invocationId, Agent $agent): void
    {
        $this->textGateway()->onToolInvocation(
            invoking: function (Tool $tool, array $arguments) use ($invocationId, $agent) {
                $this->currentToolInvocationId = (string) Str::uuid7();

                $this->events->dispatch(new InvokingTool(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments
                ));
            },
            invoked: function (Tool $tool, array $arguments, mixed $result) use ($invocationId, $agent) {
                $this->events->dispatch(new ToolInvoked(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments, $result
                ));
            },
        );
    }
}
