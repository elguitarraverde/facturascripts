<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('Dinamic')
    ->exclude('MyFiles')
    ->exclude('Plugins')
    ->exclude('vendor')
    ->exclude('node_modules');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x1Migration:risky' => true,
        "return_type_declaration" => [
            "space_before" => "none",
        ],
        "single_blank_line_at_eof" => true,
        "use_arrow_functions" => false,
        "random_api_migration" => false,
        'visibility_required' => [
            'elements' => [
                'method',
                'property',
            ],
        ],
        'declare_strict_types' => false,
        'trailing_comma_in_multiline' => false,
        'modifier_keywords' => [
            'elements' => ['method', 'property'],
        ],
        'octal_notation' => false,
        'method_argument_space' => [
            'on_multiline' => 'ignore',
        ],
        'heredoc_indentation' => false,
    ])
    ->setFinder($finder);
