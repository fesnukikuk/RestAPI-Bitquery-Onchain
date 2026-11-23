<?php

namespace App\Exceptions;

use RuntimeException;

/** Error dari Bitquery. $message aman ditampilkan ke pengunjung, $detail hanya untuk mode debug. */
class BitqueryException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $detail = null)
    {
        parent::__construct($message);
    }
}
