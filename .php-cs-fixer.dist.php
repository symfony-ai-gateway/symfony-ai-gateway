<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP82Migration' => true,
        '@PHP82Migration:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short_syntax'],
        'declare_strict_types' => true,
        'ordered_imports' => true,
        'ordered_class_elements' => true,
        'phpdoc_order' => true,
        'strict_param' => true,
        'strict_comparison' => true,
        'blank_line_before_statement' => true,
        'single_quote' => true,
        'no_unused_imports' => true,
        'no_superfluous_phpdoc_tags' => true,
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],
        'nullable_type_declaration' => ['syntax' => 'union_syntax'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__ . '/src')
            ->in(__DIR__ . '/tests')
            ->name('*.php')
            ->notName('*.blade.php')
    );
