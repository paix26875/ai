<?php

use Laravel\Ai\Schema\StructuredOutputValidator;

test('it passes when numeric values are within range', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
    ];

    expect(StructuredOutputValidator::validate($schema, ['score' => 8]))->toBe([]);
});

test('it reports numeric values out of range', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
    ];

    expect(StructuredOutputValidator::validate($schema, ['score' => 15]))
        ->toBe(['score: must be <= 10, got 15.']);
});

test('it validates string length constraints', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 5]],
    ];

    expect(StructuredOutputValidator::validate($schema, ['name' => 'ab']))
        ->toBe(['name: must be at least 3 characters, got 2.']);
});

test('it validates multipleOf', function () {
    $schema = ['type' => 'object', 'properties' => ['n' => ['type' => 'number', 'multipleOf' => 5]]];

    expect(StructuredOutputValidator::validate($schema, ['n' => 12]))
        ->toBe(['n: must be a multiple of 5, got 12.'])
        ->and(StructuredOutputValidator::validate($schema, ['n' => 15]))->toBe([]);
});

test('it validates array item count, uniqueness, and item schemas', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'tags' => [
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 3,
                'uniqueItems' => true,
                'items' => ['type' => 'string', 'maxLength' => 4],
            ],
        ],
    ];

    expect(StructuredOutputValidator::validate($schema, ['tags' => ['a', 'a', 'toolong', 'x']]))
        ->toBe([
            'tags: must have at most 3 items, got 4.',
            'tags: must have unique items.',
            'tags[2]: must be at most 4 characters, got 7.',
        ]);
});

test('it does not report structural issues like missing required keys or wrong types', function () {
    $schema = [
        'type' => 'object',
        'properties' => ['symbol' => ['type' => 'string', 'minLength' => 1]],
        'required' => ['symbol'],
    ];

    // The provider's structured output guarantees shape; only constraints are checked here.
    expect(StructuredOutputValidator::validate($schema, ['name' => 'Taylor', 'age' => 30]))->toBe([]);
});

test('it accepts a fully conforming nested payload', function () {
    $schema = [
        'type' => 'object',
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
            'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 280],
            'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'items' => ['type' => 'string']],
        ],
    ];

    expect(StructuredOutputValidator::validate($schema, [
        'score' => 8,
        'summary' => 'Looks good.',
        'tags' => ['php', 'laravel'],
    ]))->toBe([]);
});
