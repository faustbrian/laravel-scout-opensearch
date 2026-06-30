<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\CodingStandard\EasyCodingStandard\Factory;
use PhpCsFixer\Fixer\ClassNotation\FinalClassFixer;

return Factory::configure(
    paths: [__DIR__.'/src', __DIR__.'/tests'],
    skip: [
        FinalClassFixer::class => [
            __DIR__.'/tests/Fixtures/SearchableModel.php',
        ],
    ],
);
