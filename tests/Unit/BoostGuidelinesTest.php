<?php

use Illuminate\Support\Facades\Blade;

const GUIDELINES_DIR = __DIR__.'/../../resources/boost/guidelines/laravel-licensing';
const GUIDELINE_FILE = 'core.blade.php';

it('ships a single consolidated Boost guideline file', function () {
    expect(GUIDELINES_DIR.'/'.GUIDELINE_FILE)->toBeFile();

    // Boost's GuidelineComposer overwrites per-package entries on each file
    // iteration (see vendor/laravel/boost/src/Install/GuidelineComposer.php),
    // so only one file per third-party package survives the discovery pass.
    expect(glob(GUIDELINES_DIR.'/*.blade.php'))->toHaveCount(1);
});

it('compiles the Boost guideline file via Blade without errors', function () {
    $contents = file_get_contents(GUIDELINES_DIR.'/'.GUIDELINE_FILE);

    expect($contents)->not->toBeEmpty();

    $rendered = Blade::render($contents, [], deleteCachedView: true);

    expect($rendered)->toBeString()->not->toBeEmpty();
});

it('mentions every covered topic in the consolidated guideline', function () {
    $contents = file_get_contents(GUIDELINES_DIR.'/'.GUIDELINE_FILE);

    expect($contents)
        ->toContain('laravel-licensing')
        ->toContain('LicenseUsage')
        ->toContain('licensable')
        ->toContain('UsageRegistrar')
        ->toContain('createFromTemplate')
        ->toContain('TrialService')
        ->toContain('PASETO')
        ->toContain('licensing:keys:make-root')
        ->toContain('throttle:licensing-validate');
});
