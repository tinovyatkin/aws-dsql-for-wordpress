<?php
/**
 * Initialize the Aurora DSQL WordPress database adapter.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
if (DB_DRIVER !== 'dsql') {
    throw new RuntimeException('AWS DSQL for WordPress supports only DB_DRIVER=dsql.');
}

require_once dirname(PG4WP_ROOT) . '/upgrade/Context.php';
$dsqlUpgrade = \WPDSQLUpgrade\Context::load(ABSPATH, DB_HOST);
\WPDSQLUpgrade\Context::blockUnlessOwner(ABSPATH);
require_once PG4WP_ROOT . '/dsql/class-dsql-wpdb.php';
$wpdb = new DSQL_WPDB($dsqlUpgrade ? $dsqlUpgrade->data['target']['user'] : DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
if ($dsqlUpgrade) {
    $wpdb->enable_schema_upgrade($dsqlUpgrade);
}
