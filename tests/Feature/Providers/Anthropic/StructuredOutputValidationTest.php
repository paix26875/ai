<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\StructuredOutputValidationException;
use Tests\Fixtures\Agents\ConstrainedStructuredAgent;

describe('structured output validation', function () {
    test('it re-prompts until the structured output satisfies the schema', function () {
        config(['ai.structured_output' => ['max_retries' => 2, 'on_failure' => 'throw']]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->pushResponse($this->fakeStructuredResponse(['score' => 15, 'summary' => 'Good', 'tags' => ['php']]))
                ->pushResponse($this->fakeStructuredResponse(['score' => 8, 'summary' => 'Good', 'tags' => ['php']])),
        ]);

        $response = (new ConstrainedStructuredAgent)->prompt('Review this code', provider: 'anthropic');

        expect($response->structured)->toMatchArray(['score' => 8])
            ->and($response->validationErrors)->toBe([]);

        Http::assertSentCount(2);
    });

    test('it throws after exhausting retries by default', function () {
        config(['ai.structured_output' => ['max_retries' => 1, 'on_failure' => 'throw']]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['score' => 15, 'summary' => 'Good', 'tags' => ['php']]),
        ]);

        expect(fn () => (new ConstrainedStructuredAgent)->prompt('Review this code', provider: 'anthropic'))
            ->toThrow(
                StructuredOutputValidationException::class,
                'score: must be <= 10, got 15.',
            );

        // Initial attempt plus one retry.
        Http::assertSentCount(2);
    });

    test('it returns the last response with validation errors when on_failure is return', function () {
        config(['ai.structured_output' => ['max_retries' => 1, 'on_failure' => 'return']]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['score' => 15, 'summary' => 'Good', 'tags' => ['php']]),
        ]);

        $response = (new ConstrainedStructuredAgent)->prompt('Review this code', provider: 'anthropic');

        expect($response->structured)->toMatchArray(['score' => 15])
            ->and($response->validationErrors)->toBe(['score: must be <= 10, got 15.']);

        Http::assertSentCount(2);
    });

    test('it re-prompts with the violations and the previous response', function () {
        config(['ai.structured_output' => ['max_retries' => 1, 'on_failure' => 'return']]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['score' => 15, 'summary' => 'Good', 'tags' => ['php']]),
        ]);

        (new ConstrainedStructuredAgent)->prompt('Review this code', provider: 'anthropic');

        $bodies = [];

        Http::assertSent(function ($request) use (&$bodies) {
            $bodies[] = $request->data();

            return true;
        });

        $retryMessages = collect($bodies[1]['messages'])
            ->pluck('content')
            ->flatten()
            ->map(fn ($content) => is_array($content) ? ($content['text'] ?? '') : $content)
            ->implode("\n");

        expect($retryMessages)
            ->toContain('did not satisfy the required output schema')
            ->toContain('score: must be <= 10, got 15.');
    });
});
