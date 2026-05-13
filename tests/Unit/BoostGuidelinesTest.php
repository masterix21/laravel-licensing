<?php

use Illuminate\Support\Facades\Blade;

const GUIDELINES_DIR = __DIR__.'/../../resources/boost/guidelines/laravel-licensing';

const GUIDELINE_FILES = [
    'core.blade.php',
    'licenses.blade.php',
    'usages.blade.php',
    'scopes-templates.blade.php',
    'trials.blade.php',
    'offline-tokens.blade.php',
    'cli.blade.php',
    'api-security.blade.php',
];

it('ships all expected Boost guideline files', function () {
    foreach (GUIDELINE_FILES as $file) {
        expect(GUIDELINES_DIR.'/'.$file)->toBeFile();
    }
});

it('compiles every Boost guideline file via Blade without errors', function (string $file) {
    $contents = file_get_contents(GUIDELINES_DIR.'/'.$file);

    expect($contents)->not->toBeEmpty();

    $rendered = Blade::render($contents, [], deleteCachedView: true);

    expect($rendered)->toBeString()->not->toBeEmpty();
})->with(GUIDELINE_FILES);

it('mentions key package concepts in core guideline', function () {
    $core = file_get_contents(GUIDELINES_DIR.'/core.blade.php');

    expect($core)
        ->toContain('laravel-licensing')
        ->toContain('License')
        ->toContain('LicenseUsage')
        ->toContain('licensable');
});
