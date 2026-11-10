<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = (new Finder())
    ->in(__DIR__ . '/src');

return (new Config())
    ->setRules([
        '@PSR12' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
    ])
    ->setFinder($finder)
    ->setIndent('    ')
    ->setRiskyAllowed(false);
