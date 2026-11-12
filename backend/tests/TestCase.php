<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tes memakai salinan database proenergi LOKAL (data nyata) dan selalu
     * di-rollback. Dihentikan bila bukan database lokal supaya tidak pernah
     * menyentuh data produksi.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $host = config('database.connections.mysql.host');
        if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->fail("Tes ditolak: DB_HOST '{$host}' bukan database lokal.");
        }
    }
}
