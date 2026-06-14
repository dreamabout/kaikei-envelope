<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                                 => true,
        '@PSR12:risky'                            => true,
        'array_syntax'                           => ['syntax' => 'short'],
        'trailing_comma_in_multiline'            => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_unused_imports'                      => true,
        'ordered_imports'                        => ['sort_algorithm' => 'alpha'],
        'declare_strict_types'                   => true,
        'no_superfluous_phpdoc_tags'             => ['allow_mixed' => true],
        'single_quote'                           => true,
        'concat_space'                           => ['spacing' => 'one'],
        'method_chaining_indentation'            => true,
        'no_trailing_whitespace'                 => true,
        'no_whitespace_in_blank_line'            => true,
        'single_blank_line_at_eof'               => true,
        'no_extra_blank_lines'                   => true,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
