<?php

namespace App\Services\Accurate;

/**
 * Trucking Instruction received ke Accurate: penyesuaian stok OUT di gudang loading dan IN di gudang discharging (item-adjustment/save.do).
 * Sumber: web/action/trucking-instruction.php aksi received. Bila salah satu gagal, transaksi yang sudah masuk dihapus kembali (delete.do).
 */
class AccurateTiSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    private static function cabang(?string $n): ?string
    {
        return $n === 'Kantor Pusat' ? 'Head Office' : $n;
    }

    private function simpan(array $data, string $label): string
    {
        $r = $this->api->post('item-adjustment/save.do', $data);
        if (empty($r['s'])) {
            throw new AccurateException(($r['d'][0] ?? 'Gagal terhubung ke Accurate').' - Response dari Accurate ('.$label.')');
        }

        return (string) $r['r']['id'];
    }

    /** @return string id transaksi OUT */
    public function out(string $nomor, string $akun, string $tanggal, ?string $ket, ?string $cabangUser, string $item, float $qty, ?string $gudang, ?string $dept): string
    {
        return $this->simpan([
            'number' => $nomor, 'adjustmentAccountNo' => $akun, 'transDate' => $tanggal, 'description' => $ket, 'branchName' => self::cabang($cabangUser),
            'detailItem' => [['itemAdjustmentType' => 'ADJUSTMENT_OUT', 'itemNo' => $item, 'quantity' => $qty, 'warehouseName' => $gudang, 'departmentName' => $dept]],
        ], 'OUT '.$nomor);
    }

    /** @return string id transaksi IN (departemen mengikuti cabang loading, seperti web) */
    public function in(string $nomor, string $akun, string $tanggal, ?string $ket, ?string $cabangUser, string $item, float $qty, float $harga, ?string $gudang, ?string $dept): string
    {
        return $this->simpan([
            'number' => $nomor, 'adjustmentAccountNo' => $akun, 'transDate' => $tanggal, 'description' => $ket, 'branchName' => self::cabang($cabangUser),
            'detailItem' => [['itemAdjustmentType' => 'ADJUSTMENT_IN', 'itemNo' => $item, 'quantity' => $qty, 'unitCost' => $harga, 'warehouseName' => $gudang, 'departmentName' => $dept]],
        ], 'IN '.$nomor);
    }

    /** Hapus transaksi yang sudah terkirim; mengembalikan id yang gagal dihapus. */
    public function batalkan(array $ids): array
    {
        $gagal = [];
        foreach ($ids as $id) {
            $r = $this->api->post('item-adjustment/delete.do', ['id' => $id]);
            if (empty($r['s'])) {
                $gagal[] = $id;
            }
        }

        return $gagal;
    }

    /** Detail transaksi penyesuaian dari Accurate (item-adjustment/detail.do). @return array{ok:bool, pesan?:string, d?:array} */
    public function detail($id): array
    {
        $r = $this->api->get('item-adjustment/detail.do', ['id' => $id]);
        if (empty($r['s'])) {
            return ['ok' => false, 'pesan' => $r['d'][0] ?? 'Gagal memanggil API Accurate'];
        }

        return ['ok' => true, 'd' => $r['d'] ?? []];
    }
}
