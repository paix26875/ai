<?php

use Laravel\Ai\Schema\StructuredOutputNormalizer;

$unsupported = ['minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems'];

test('it removes unsupported keywords and folds them into the description', function () use ($unsupported) {
    $stripped = StructuredOutputNormalizer::strip([
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 10,
    ], $unsupported);

    expect($stripped)->toBe([
        'type' => 'integer',
        'description' => 'Constraints: minimum 1, maximum 10.',
    ]);
});

test('it appends constraint notes to an existing description', function () use ($unsupported) {
    $stripped = StructuredOutputNormalizer::strip([
        'type' => 'string',
        'description' => 'A short summary.',
        'minLength' => 1,
        'maxLength' => 280,
    ], $unsupported);

    expect($stripped['description'])->toBe('A short summary. Constraints: at least 1 characters, at most 280 characters.')
        ->and($stripped)->not->toHaveKeys(['minLength', 'maxLength']);
});

test('it removes unsupported keywords from nested object properties', function () use ($unsupported) {
    $stripped = StructuredOutputNormalizer::strip([
        'type' => 'object',
        'properties' => [
            'score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
        ],
        'required' => ['score'],
    ], $unsupported);

    expect($stripped['properties']['score'])->not->toHaveKeys(['minimum', 'maximum'])
        ->and($stripped['properties']['score']['type'])->toBe('integer')
        ->and($stripped['properties']['score']['description'])->toBe('Constraints: minimum 1, maximum 10.')
        ->and($stripped['required'])->toBe(['score']);
});

test('it removes unsupported keywords from array items and the array node', function () use ($unsupported) {
    $stripped = StructuredOutputNormalizer::strip([
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => 5,
        'uniqueItems' => true,
        'items' => ['type' => 'integer', 'minimum' => 0],
    ], $unsupported);

    expect($stripped)->not->toHaveKeys(['minItems', 'maxItems', 'uniqueItems'])
        ->and($stripped['description'])->toBe('Constraints: at least 1 items, at most 5 items, unique items.')
        ->and($stripped['items'])->not->toHaveKey('minimum')
        ->and($stripped['items']['description'])->toBe('Constraints: minimum 0.');
});

test('it removes unsupported keywords inside union branches', function () use ($unsupported) {
    $stripped = StructuredOutputNormalizer::strip([
        'anyOf' => [
            ['type' => 'integer', 'minimum' => 1],
            ['type' => 'string', 'maxLength' => 10],
        ],
    ], $unsupported);

    expect($stripped['anyOf'][0])->not->toHaveKey('minimum')
        ->and($stripped['anyOf'][1])->not->toHaveKey('maxLength');
});

test('it leaves supported keywords untouched', function () use ($unsupported) {
    $schema = [
        'type' => 'object',
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
            'email' => ['type' => 'string', 'format' => 'email'],
        ],
        'required' => ['status'],
        'additionalProperties' => false,
    ];

    expect(StructuredOutputNormalizer::strip($schema, $unsupported))->toBe($schema);
});
