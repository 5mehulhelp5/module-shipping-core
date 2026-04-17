<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

/*
 * Worker helper for {@see \Shubo\ShippingCore\Test\Integration\Model\Resilience\RateLimiterConcurrencyTest}.
 *
 * Spawned via proc_open() by the parent test. Each invocation boots its
 * own Magento instance (fresh DB connection + fresh object graph), then
 * calls RateLimiter::acquire() a configurable number of times against
 * the carrier_code given on the command line.
 *
 * Usage:
 *     php rate_limit_worker.php <carrier_code> <acquires> <worker_id>
 *
 * Output: one line per acquire attempt, either "OK" or "NO", followed by
 * a final "DONE <worker_id>" marker. Errors are printed to stderr and
 * cause a non-zero exit code so the parent test notices.
 */

// This is a standalone worker script spawned via proc_open() by the
// concurrency test. It boots Magento manually and uses exit codes to
// signal status back to the parent — both patterns are forbidden in
// regular module code but are the correct pattern for a CLI helper.
// phpcs:disable Magento2.Security.IncludeFile.FoundIncludeFile
// phpcs:disable Magento2.Functions.DiscouragedFunction
// phpcs:disable Magento2.Security.Superglobal
// phpcs:disable Magento2.Security.LanguageConstruct.ExitUsage

if ($argc < 4) {
    fwrite(STDERR, "usage: rate_limit_worker.php <carrier_code> <acquires> <worker_id>\n");
    exit(2);
}

$carrierCode = (string)$argv[1];
$acquires = (int)$argv[2];
$workerId = (string)$argv[3];

// Bootstrap Magento from the container's document root. Running under
// integration PHPUnit would point BP there anyway; doing it explicitly
// keeps this helper usable from any CWD.
$bootstrapPath = '/var/www/html/app/bootstrap.php';
if (!is_file($bootstrapPath)) {
    fwrite(STDERR, "worker {$workerId}: missing bootstrap {$bootstrapPath}\n");
    exit(3);
}
require $bootstrapPath;

try {
    $params = $_SERVER;
    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $params);
    $objectManager = $bootstrap->getObjectManager();
    $limiter = $objectManager->get(\Shubo\ShippingCore\Api\RateLimiterInterface::class);
} catch (\Throwable $e) {
    fwrite(STDERR, "worker {$workerId}: bootstrap failed: " . $e->getMessage() . "\n");
    exit(4);
}

// Buffer results and flush at the end so inter-worker output interleaving
// at stdout does not produce half-written lines the parent can't parse.
$lines = [];
for ($i = 0; $i < $acquires; $i++) {
    try {
        $ok = $limiter->acquire($carrierCode, 1);
        $lines[] = $ok ? 'OK' : 'NO';
    } catch (\Throwable $e) {
        fwrite(STDERR, "worker {$workerId}: acquire #{$i} threw: " . $e->getMessage() . "\n");
        exit(5);
    }
}

fwrite(STDOUT, implode("\n", $lines) . "\nDONE {$workerId}\n");
exit(0);
