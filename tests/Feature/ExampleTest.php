<?php

// Replaces Laravel's default example (which expected "/" to be public). Checks only that the app boots.
test('app boots', function () {
    expect(app()->environment())->toBe('testing');
});
