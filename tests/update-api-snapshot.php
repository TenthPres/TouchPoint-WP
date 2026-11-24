<?php
/**
 * Regenerates tests/api-surface.json.  Run via `composer update-api-snapshot` after an INTENTIONAL API change,
 * then review the diff and commit it with the change.
 */

require_once __DIR__ . '/bootstrap.php';

use tp\TouchPointWP\Tests\ApiSurface;

file_put_contents(ApiSurface::SNAPSHOT_PATH, ApiSurface::encode(ApiSurface::build()));
echo "Wrote " . ApiSurface::SNAPSHOT_PATH . "\n";
