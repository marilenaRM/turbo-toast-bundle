<?php

declare(strict_types=1);

use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;

$finder = new Finder();
$finder->in(__DIR__ . '/templates');

$config = new Config();
$config->setFinder($finder);

return $config;
