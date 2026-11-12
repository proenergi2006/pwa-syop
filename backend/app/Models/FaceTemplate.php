<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Template wajah terenkripsi. Jangan pernah dikirim ke klien atau dicatat di log. */
class FaceTemplate extends Model
{
    protected $table = 'mobile_face_templates';
    protected $guarded = [];
    protected $hidden = ['template'];

    protected function casts(): array
    {
        return [
            'template'     => 'encrypted:array',
            'last_used_at' => 'datetime',
        ];
    }
}
