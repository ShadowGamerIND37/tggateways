<?php
declare(strict_types=1);
/**
 * Environment access shared by the bootstrap modules. Kept separate so modules
 * that need configuration (client address resolution, error handling) do not
 * depend on the database layer.
 */

/** Read a configuration value from the environment. */
function envv(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    return $v === false ? $default : $v;
}
