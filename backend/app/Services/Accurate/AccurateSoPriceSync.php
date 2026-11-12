<?php

namespace App\Services\Accurate;

use Illuminate\Support\Facades\DB;

/**
 * Perubahan harga disetujui -> perbarui harga item pada semua Sales Order Accurate milik penawaran itu
 * (approvalPenawaranSyncAccurate di penawaran-approval (1).php): baca sales-order/detail.do paralel (maks 5), ubah unitPrice per item
 * sesuai jenis penawaran, simpan lewat sales-order/save.do paralel (maks 5). Gagal satu pun = seluruh persetujuan dibatalkan.
 */
class AccurateSoPriceSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    /** id Sales Order Accurate yang harus ikut diubah (plan aktif dan belum dibatalkan pengirimannya). */
    public function salesOrderIds(int $idPenawaran): array
    {
        return array_values(array_unique(array_map(fn ($r) => (string) $r->id_accurate, DB::select("
            select distinct c.id_accurate
            from pro_po_customer b
                join pro_po_customer_plan c on b.id_poc = c.id_poc
            where b.id_penawaran = ? and c.status_plan = 1 and c.id_accurate is not null and c.id_accurate <> ''
              and not exists (select 1 from pro_po_ds_detail dsd where dsd.id_plan = c.id_plan and dsd.is_cancel = 1)", [$idPenawaran]))));
    }

    /** Harga baru tiap item SO: aturan sama dengan approvalPenawaranBuildAccurateDetail. */
    public static function buildDetail(array $detailItems, string $jenis, array $p): array
    {
        $out = [];
        foreach ($detailItems as $item) {
            $id = $item['id'] ?? null;
            $no = $item['item']['no'] ?? null;
            if (! $id || ! $no) {
                continue;
            }
            $pbbkb = $no === 'PBBKB';
            $oa = $no === 'NS-001';
            $ppn = $no === 'NS-009';
            $hsd = ! $pbbkb && ! $oa && ! $ppn;
            $price = null;
            if ($jenis === 'gabung_oa') {
                $price = $hsd ? $p['harga_dasar'] + $p['oa'] : ($oa ? 0 : ($pbbkb ? $p['pbbkb'] : $p['ppn']));
            } elseif ($jenis === 'gabung_pbbkb') {
                $price = $hsd ? $p['harga_dasar'] + $p['pbbkb'] : ($pbbkb ? 0 : ($oa ? $p['oa'] : $p['ppn']));
            } elseif ($jenis === 'break_all') {
                $price = $hsd ? $p['harga_dasar'] : ($pbbkb ? $p['pbbkb'] : ($oa ? $p['oa'] : $p['ppn']));
            } else { // all_in / gabung_pbbkboa
                $price = $hsd ? $p['harga_dasar'] + $p['pbbkb'] + $p['oa'] : (($pbbkb || $oa) ? 0 : $p['ppn']);
            }
            $out[] = ['id' => $id, 'unitPrice' => $price];
        }

        return $out;
    }

    private static function msg(array $r, string $default, string $context, string $id): string
    {
        $m = $r['d'][0] ?? ($r['d']['message'] ?? null);
        $m = is_scalar($m) && trim((string) $m) !== '' ? trim((string) $m) : (empty($r) ? 'Accurate Online tidak memberikan response.' : $default);

        return $m.' - '.$context.' Accurate SO ID '.$id;
    }

    /** @param  array{harga_dasar:float,oa:float,ppn:float,pbbkb:float}  $price */
    public function sync(int $idPenawaran, ?string $branchName, string $jenis, array $price): void
    {
        if (! $this->enabled()) {
            return;
        }
        $ids = $this->salesOrderIds($idPenawaran);
        if (! $ids) {
            return;
        }
        $detailReq = [];
        foreach ($ids as $id) {
            $detailReq[$id] = ['method' => 'GET', 'path' => 'sales-order/detail.do', 'query' => ['id' => $id]];
        }
        $details = $this->api->batch($detailReq, 5);
        $saveReq = [];
        foreach ($ids as $id) {
            $r = $details[$id] ?? [];
            if (empty($r['s'])) {
                throw new AccurateException(self::msg($r, 'Gagal mengambil detail Sales Order', 'Detail', (string) $id));
            }
            $items = self::buildDetail(is_array($r['d']['detailItem'] ?? null) ? $r['d']['detailItem'] : [], $jenis, $price);
            if (! $items) {
                continue;
            }
            $saveReq[$id] = ['method' => 'POST', 'path' => 'sales-order/save.do', 'data' => ['id' => $id, 'detailItem' => $items, 'branchName' => $branchName]];
        }
        if (! $saveReq) {
            return;
        }
        $saved = $this->api->batch($saveReq, 5);
        foreach ($saveReq as $id => $_) {
            $r = $saved[$id] ?? [];
            if (empty($r['s'])) {
                throw new AccurateException(self::msg($r, 'Update Sales Order gagal', 'Save', (string) $id));
            }
        }
    }
}
