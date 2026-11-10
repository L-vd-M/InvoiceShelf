<?php

use App\Services\Document\RevisionDiffService;

$fixtures = glob(__DIR__.'/../Fixtures/revision-diff/*.json');

test('golden fixtures exist', function () use ($fixtures) {
    expect(count($fixtures))->toBeGreaterThanOrEqual(10);
});

foreach ($fixtures as $file) {
    test('diff golden: '.basename($file, '.json'), function () use ($file) {
        $case = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        expect(RevisionDiffService::diff($case['before'], $case['after']))->toEqual($case['expected']);
    });
}

test('summarise counts changes per kind and treats legacy rows without a kind as header changes', function () {
    $summary = RevisionDiffService::summarise([
        ['kind' => 'quantity_changed', 'path' => 'items[0].quantity'],
        ['kind' => 'quantity_changed', 'path' => 'items[1].quantity'],
        ['path' => 'notes', 'before' => 'a', 'after' => 'b'],
    ]);

    expect($summary)->toBe(['changes' => 3, 'kinds' => ['quantity_changed' => 2, 'header_changed' => 1]])
        ->and(RevisionDiffService::summarise(null))->toBe(['changes' => 0, 'kinds' => []]);
});
