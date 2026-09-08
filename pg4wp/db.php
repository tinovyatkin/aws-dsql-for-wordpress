<?php
/*
Plugin Name: AWS DSQL for WordPress
Plugin URI: https://github.com/tinovyatkin/aws-dsql-for-wordpress
Description: Experimental Aurora DSQL database drop-in, derived from PostgreSQL for WordPress.
Version: 0.8.0
Author: tinovyatkin and PG4WP contributors
License: GPLv2 or newer.
*/

// Ensure we only load this config once
if(!defined('PG4WP_BOOTSTRAPPED')) {
    define('PG4WP_BOOTSTRAPPED', true);

    // DSQL is the only supported driver.
    if (!defined('DB_DRIVER')) {
        define('DB_DRIVER', 'dsql');
    }

    // This defines the directory where PG4WP files are loaded from
    //   3 places checked : wp-content, wp-content/plugins and the base directory
    if (defined('PG4WP_ROOT')) {
        // Explicit development path.
    } elseif(file_exists(ABSPATH . 'wp-content/plugins/aws-dsql-for-wordpress/pg4wp')) {
        define('PG4WP_ROOT', ABSPATH . 'wp-content/plugins/aws-dsql-for-wordpress/pg4wp');
    } elseif(file_exists(ABSPATH . 'wp-content/pg4wp')) {
        define('PG4WP_ROOT', ABSPATH . 'wp-content/pg4wp');
    } elseif(file_exists(ABSPATH . 'wp-content/plugins/pg4wp')) {
        define('PG4WP_ROOT', ABSPATH . 'wp-content/plugins/pg4wp');
    } elseif(file_exists(ABSPATH . 'pg4wp')) {
        define('PG4WP_ROOT', ABSPATH . 'pg4wp');
    } else {
        die('PG4WP file directory not found');
    }

    // Here happens all the magic
    require_once(PG4WP_ROOT . '/core.php');
} // Protection against multiple loading
