<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'vendor', 'node_modules', 'public/assets', 'graft'])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
        'config/preload.php',
    ])
;

return (new PhpCsFixer\Config())
    // Generated files belong under var/, not scattered across the project root.
    ->setCacheFile(__DIR__.'/var/cache/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        // Turnin is typed end to end; weak mode would quietly coerce a shift id
        // or a timestamp instead of failing.
        'declare_strict_types' => true,
        'strict_param' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setFinder($finder)
;
