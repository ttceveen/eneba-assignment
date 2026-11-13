<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateDigitalKeyException extends RuntimeException
{
    public function __construct()
    {
        // Deliberately omit the database exception: it contains sensitive bindings.
        parent::__construct('A digital key with this content already exists.', 23505);
    }
}
