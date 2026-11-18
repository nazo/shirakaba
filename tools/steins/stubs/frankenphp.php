<?php

// Declarations of FrankenPHP's extension functions, for Steins only. This file is
// never loaded: it is passed to `steins check` as a path so the analyser knows the signature.

/**
 * @param callable(): void $handler
 * @phpstan-impure io
 */
function frankenphp_handle_request(callable $handler): bool
{
    return false;
}
