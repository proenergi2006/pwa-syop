<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email keputusan untuk PO, SI, TI, dan DR (sama dengan PHPMailer di web lama: satu email ke semua penerima peran,
 * subjek "<jenis> [kode - dd/mm/yyyy HH:ii:ss]", isi singkat + alamat aplikasi). Penawaran punya jalur sendiri di PenawaranApprovalService.
 * Gagal kirim tidak boleh membatalkan keputusan (sama seperti web): hanya dicatat di log.
 */
class ApprovalMail
{
    /** Email akun aktif pada peran tertentu (opsional dibatasi cabang/wilayah). */
    public static function emails(array $roles, ?array $wilayah = null): array
    {
        $q = DB::table('acl_user')->whereIn('id_role', $roles)->where('is_active', 1)
            ->whereNotNull('email_user')->where('email_user', '!=', '');
        if ($wilayah !== null) {
            $q->whereIn('id_wilayah', $wilayah);
        }

        return array_values(array_unique($q->pluck('email_user')->all()));
    }

    public static function stamp(): string
    {
        return date('d/m/Y H:i:s');
    }

    /** Kirim ke penerima peran. $bodyHtml sudah di-escape pemanggil; alamat aplikasi ditambahkan di akhir seperti BASE_SERVER di web. */
    public static function toRoles(array $roles, ?array $wilayah, string $subject, string $bodyHtml): void
    {
        self::send(self::emails($roles, $wilayah), $subject, $bodyHtml);
    }

    public static function send(array $to, string $subject, string $bodyHtml): void
    {
        $to = array_values(array_filter($to, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if ($to === []) {
            return;
        }

        try {
            Mail::html($bodyHtml . '<p>' . e((string) config('app.url')) . '</p>', fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::warning('[Email] gagal dikirim (' . $subject . '): ' . $e->getMessage());
        }
    }
}
