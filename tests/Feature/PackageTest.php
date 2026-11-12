<?php

use MDDev\DynamicDashboard\Templates\DashboardTemplate;
use MDDev\DynamicDashboard\Templates\TemplateRegistry;

/**
 * Flatten a translation array to its dotted keys.
 *
 * @param  array<string, mixed>  $translations
 * @return array<int, string>
 */
function translationKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $keys = is_array($value)
            ? [...$keys, ...translationKeys($value, $prefix.$key.'.')]
            : [...$keys, $prefix.$key];
    }

    return $keys;
}

it('ships the same translation keys in every locale', function (string $file): void {
    $langPath = __DIR__.'/../../resources/lang';
    $reference = translationKeys(require $langPath.'/en/'.$file);

    foreach (glob($langPath.'/*', GLOB_ONLYDIR) as $localePath) {
        $translations = require $localePath.'/'.$file;

        expect(translationKeys($translations))->toBe($reference, basename($localePath).'/'.$file);

        array_walk_recursive($translations, function (mixed $value, string $key) use ($localePath, $file): void {
            expect($value)->toBeString()->not->toBeEmpty(basename($localePath).'/'.$file.' → '.$key);
        });
    }
})->with(['dashboard.php', 'templates.php']);

it('serves a regional locale from the language it is an alias of', function (string $alias, string $language): void {
    $expected = [];

    foreach (['dashboard.manage', 'dashboard.personal_help', 'templates.two_columns.description'] as $key) {
        app()->setLocale($language);
        $expected[$key] = __('filament-dynamic-dashboard::'.$key);

        app()->setLocale('en');
        expect($expected[$key])->not->toBe(__('filament-dynamic-dashboard::'.$key), $language.' must be translated: '.$key);
    }

    app()->setLocale($alias);

    foreach ($expected as $key => $translation) {
        expect(__('filament-dynamic-dashboard::'.$key))->toBe($translation, $alias.' → '.$key);
    }
})->with([
    'Brazilian Portuguese' => ['pt_BR', 'pt'],
    'Simplified Chinese' => ['zh_CN', 'zh'],
]);

it('resolves every translation key used by the package', function (): void {
    $sources = [
        ...glob(__DIR__.'/../../src/*.php'),
        ...glob(__DIR__.'/../../src/*/*.php'),
        ...glob(__DIR__.'/../../resources/views/*/*.blade.php'),
        ...glob(__DIR__.'/../../resources/dashboard-templates/*.json'),
    ];

    $keys = [];

    foreach ($sources as $source) {
        preg_match_all('/filament-dynamic-dashboard::(?:dashboard|templates)\.[a-z0-9_.]+/', file_get_contents($source), $matches);
        $keys = [...$keys, ...$matches[0]];
    }

    $keys = array_unique($keys);

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(__($key))->not->toBe($key, $key);
    }
});

it('registers the template registry as a singleton with the shipped presets', function (): void {
    $registry = app(TemplateRegistry::class);

    expect(app(TemplateRegistry::class))->toBe($registry)
        ->and(array_keys($registry->all()))->toEqualCanonicalizing([
            '2-columns', '2-left-1-right', '3-columns', '4-cells-2-rows',
            'flat-12', 'header-2cols-footer', 'kpi-strip-chart', 'sidebar-main',
        ])
        ->and($registry->default()->key)->toBe('flat-12');

    foreach ($registry->all() as $template) {
        expect($template)->toBeInstanceOf(DashboardTemplate::class)
            ->and($template->sections)->not->toBeEmpty()
            ->and($registry->previewSvg($template->key))->toContain('<svg');
    }
});

it('hides disabled templates from the selector but still resolves them', function (): void {
    config()->set('filament-dynamic-dashboard.disabled_templates', ['kpi-strip-chart']);

    $registry = app(TemplateRegistry::class);

    expect($registry->enabled())->not->toHaveKey('kpi-strip-chart')
        ->and($registry->find('kpi-strip-chart'))->toBeInstanceOf(DashboardTemplate::class);
});
