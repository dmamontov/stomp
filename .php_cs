<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__.'/src');


return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR2' => true,
        '@Symfony' => true,
        '@PhpCsFixer' => true
    ])
    ->setFinder($finder);
