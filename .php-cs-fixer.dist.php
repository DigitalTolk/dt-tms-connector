<?php

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'declare_strict_types' => true, 'no_unused_imports' => true, 'ordered_imports' => true])
    ->setRiskyAllowed(true)
    ->setFinder(PhpCsFixer\Finder::create()->in([__DIR__ . '/Classes', __DIR__ . '/Tests', __DIR__ . '/Configuration']));
