<?php

namespace App\Services\Accurate;

use RuntimeException;

/** Kegagalan memanggil Accurate; pesannya langsung ditampilkan ke pengguna (format sama dengan web: "<pesan Accurate> - Response dari Accurate SO"). */
class AccurateException extends RuntimeException
{
}
