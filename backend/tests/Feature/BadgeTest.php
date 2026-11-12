<?php

namespace Tests\Feature;

use App\Models\AclUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use DatabaseTransactions;

    private function as(int $role): void
    {
        Sanctum::actingAs(AclUser::where('id_role', $role)->where('is_active', 1)->firstOrFail(), ['*']);
    }

    public function test_ceo_mendapat_semua_hitungan(): void
    {
        $this->as(21);
        $this->getJson('/api/v1/badges')->assertOk()->assertJsonStructure(['penawaran', 'po', 'shipping', 'trucking']);
    }

    public function test_peran_lain_hanya_modulnya(): void
    {
        $this->as(7);
        $this->getJson('/api/v1/badges')->assertOk()->assertJson(['po' => 0, 'shipping' => 0, 'trucking' => 0]);
        $this->as(5);
        $this->getJson('/api/v1/badges')->assertOk()->assertJson(['penawaran' => 0, 'po' => 0, 'shipping' => 0, 'trucking' => 0]);
    }
}
