<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * PHPUnit bootstrap: composer autoload + ObjectManager factory stubs.
 *
 * Magento's DI generator produces the `*Factory` classes at runtime; in
 * standalone unit tests the generator is not involved, so we declare
 * lightweight stubs with the same FQCN. PHPUnit's reflection mock builder
 * picks up the stub and creates a mock as if against the real factory.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/FactoryStubs.php';
