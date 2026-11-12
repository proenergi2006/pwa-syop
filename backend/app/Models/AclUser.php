<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Akun lama ProEnergi (tabel acl_user). Tidak ada kolom created_at/updated_at
 * standar Laravel, jadi timestamps dimatikan. Password bcrypt ($2a$/$2y$)
 * diverifikasi dengan password_verify() di AuthController.
 */
class AclUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'acl_user';
    protected $primaryKey = 'id_user';
    public $timestamps = false;

    protected $hidden = ['password'];

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}
