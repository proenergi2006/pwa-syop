<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Berkas unggahan web lama (files/uploaded_user/...). Folder web dipasang ke container sebagai baca-saja di ORCA_WEB_FILES
 * (lihat docker-compose.yml). Setara download-file.php: PDF dan gambar tampil di tempat, selain itu diunduh.
 * Nama berkas tidak pernah dipercaya mentah: hanya jalur relatif di dalam folder dasar yang dilayani (tanpa ../).
 */
class LegacyFile
{
    public static function base(): string
    {
        return rtrim((string) config('orca.web_files'), '/');
    }

    /** $relative = jalur di bawah files/uploaded_user, mis. "lampiran/RA_1_1_x.pdf". Null bila tidak ada / di luar folder dasar. */
    public static function resolve(string $relative): ?string
    {
        $base = realpath(self::base().'/files/uploaded_user') ?: realpath(self::base());
        if (! $base || $relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        $path = realpath($base.'/'.ltrim($relative, '/'));

        return $path && is_file($path) && str_starts_with($path, $base.DIRECTORY_SEPARATOR) ? $path : null;
    }

    /** Dari jalur absolut milik server web lama (disimpan di JSON KYC) ke jalur relatif di bawah files/uploaded_user. */
    public static function fromStored(?string $stored): ?string
    {
        $stored = (string) $stored;
        $i = strpos($stored, 'files/uploaded_user/');

        return $i === false ? null : substr($stored, $i + strlen('files/uploaded_user/'));
    }

    /**
     * Simpan unggahan ke files/uploaded_user/<folder>/<nama> (folder dibuat bila belum ada). Nama sudah dibentuk pemanggil (tanpa ../).
     * Mengembalikan false bila folder dasar tidak dapat ditulis (mount baca-saja).
     */
    public static function store(\Illuminate\Http\UploadedFile $file, string $relative): bool
    {
        $base = realpath(self::base().'/files/uploaded_user');
        if (! $base || str_contains($relative, '..') || str_contains($relative, "\0")) {
            return false;
        }
        $target = $base.'/'.ltrim($relative, '/');
        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        return @move_uploaded_file($file->getPathname(), $target) || @copy($file->getPathname(), $target);
    }

    public static function delete(?string $relative): void
    {
        $path = $relative ? self::resolve($relative) : null;
        if ($path) {
            @unlink($path);
        }
    }

    public static function response(?string $relative, string $downloadName): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $path = $relative ? self::resolve($relative) : null;
        if (! $path) {
            return response()->json(['message' => 'Berkas tidak ditemukan di server.', 'code' => 'FILE_MISSING'], 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inline = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
        $name = preg_replace('/[^\w.\- ]+/u', '_', $downloadName) ?: 'berkas';

        return response()->file($path, [
            'Content-Type' => $inline[$ext] ?? 'application/octet-stream',
            'Content-Disposition' => (isset($inline[$ext]) ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
