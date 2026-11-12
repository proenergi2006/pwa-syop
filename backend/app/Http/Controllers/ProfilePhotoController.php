<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Foto profil milik user yang sedang login (hanya miliknya sendiri; tidak ada endpoint untuk melihat foto orang lain).
 * Gambar dibaca ulang dan disimpan sebagai JPEG 256x256: membuang EXIF (lokasi GPS), dan menolak berkas yang
 * bukan gambar sungguhan.
 */
class ProfilePhotoController extends Controller
{
    private const SIZE = 256;

    private function path(int $id): string
    {
        return "orca/photos/{$id}.jpg";
    }

    public function show(Request $request): Response|JsonResponse
    {
        $row = DB::table('mobile_user_photos')->where('id_user', $request->user()->id_user)->first();
        if (! $row || ! Storage::disk('local')->exists($row->path)) {
            return response()->json(['message' => 'Belum ada foto.'], 404);
        }

        $etag = '"' . md5($row->path . $row->updated_at) . '"';

        return response(Storage::disk('local')->get($row->path), 200, [
            'Content-Type'           => 'image/jpeg',
            'Cache-Control'          => 'private, max-age=0, must-revalidate',
            'ETag'                   => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp'],
        ], [
            'photo.max'       => 'Ukuran foto maksimal 5 MB.',
            'photo.mimetypes' => 'Foto harus berformat JPG, PNG, atau WebP.',
        ]);

        $file = $request->file('photo');
        $info = @getimagesize($file->getRealPath());
        if ($info === false || $info[0] * $info[1] > 40_000_000) {
            return response()->json(['message' => 'Berkas bukan gambar yang valid.'], 422);
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG  => @imagecreatefrompng($file->getRealPath()),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file->getRealPath()),
            default        => false,
        };
        if (! $src) {
            return response()->json(['message' => 'Foto tidak dapat dibaca.'], 422);
        }

        // Potong persegi dari tengah, lalu perkecil.
        [$w, $h] = [imagesx($src), imagesy($src)];
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG transparan -> latar putih
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($dst, null, 85);
        $jpeg = (string) ob_get_clean();

        $id = (int) $request->user()->id_user;
        Storage::disk('local')->put($this->path($id), $jpeg);
        DB::table('mobile_user_photos')->updateOrInsert(
            ['id_user' => $id],
            ['path' => $this->path($id), 'bytes' => strlen($jpeg), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['photo_v' => now()->timestamp]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $id = (int) $request->user()->id_user;
        Storage::disk('local')->delete($this->path($id));
        DB::table('mobile_user_photos')->where('id_user', $id)->delete();

        return response()->json(['photo_v' => null]);
    }
}
