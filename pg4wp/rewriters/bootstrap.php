<?php
/** Load the PG4WP-derived SQL translation classes used by DSQL. */
spl_autoload_register(static function (string $className): void {
    $file = __DIR__ . '/' . $className . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
