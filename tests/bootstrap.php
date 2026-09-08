<?php
// Test shared translation classes without WordPress, a driver, or a database.
define('PG4WP_DEBUG', false);
require_once dirname(__DIR__) . '/pg4wp/rewriters/bootstrap.php';
require_once __DIR__ . '/support/rewrite-fixture.php';
