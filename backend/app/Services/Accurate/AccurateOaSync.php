<?php

namespace App\Services\Accurate;

use Illuminate\Support\Facades\DB;

/**
 * Sinkron perubahan source/OA ke Accurate saat CEO menyetujui Request Input OA, setara dengan bagian Accurate pada
 * verifikasi-request-oa-ceo.php: kode item Sales Order dan Delivery Order ikut berubah mengikuti PO Supplier baru
 * (kode item Accurate melekat ke PO Supplier yang dipilih), item non-inventory (pajak dkk) tetap.
 *   urutan wajib: Delivery Order dulu, baru Sales Order (baris DO menunjuk baris SO, jadi baris SO tidak bisa diganti lebih dulu).
 * Hanya berjalan bila klien aktif dan DR sudah tersambung ke Accurate (id_do_accurate dan id Sales Order terisi); selain itu dilewati.
 * Sama dengan web: bila Delivery Order sudah tersimpan lalu Sales Order gagal, Delivery Order tidak dikembalikan (yang dibatalkan hanya database).
 */
class AccurateOaSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    /**
     * @param  array<int, object>  $srcBaru  baris pro_po_request_oa_detail (jenis 2), berurutan
     *
     * @throws AccurateException
     */
    public function apply(int $idPrd, array $srcBaru): void
    {
        if (! $this->enabled()) {
            return;
        }
        $acc = DB::selectOne('select a.id_do_accurate, a.no_do_syop, b.id_accurate as id_so_accurate, b.no_so, d.kode_pelanggan, e.nama_cabang
            from pro_pr_detail a
                join pro_po_customer_plan b on a.id_plan = b.id_plan
                join pro_po_customer c on b.id_poc = c.id_poc
                join pro_penawaran p on p.id_penawaran = c.id_penawaran
                join pro_customer d on c.id_customer = d.id_customer
                join pro_master_cabang e on p.id_cabang = e.id_master
            where a.id_prd = ?', [$idPrd]);
        // DR yang belum pernah tersambung ke Accurate dilewati, bukan error.
        if (! $acc || ! $acc->id_so_accurate || ! $acc->id_do_accurate) {
            return;
        }
        $branch = $acc->nama_cabang === 'Kantor Pusat' ? 'Head Office' : $acc->nama_cabang;

        // Kode item dan gudang Accurate untuk tiap porsi source baru (1 porsi, atau 2 kalau split).
        $perPorsi = [];
        foreach ($srcBaru as $s) {
            $po = DB::table('new_pro_inventory_vendor_po')->where('nomor_po', $s->nomor_po_supplier)->value('id_accurate');
            if (! $po) {
                throw new AccurateException("PO Supplier {$s->nomor_po_supplier} belum terhubung ke Accurate");
            }
            $r = $this->api->get('purchase-order/detail.do', ['id' => $po]);
            $itemNo = null;
            if (! empty($r['s'])) {
                foreach ($r['d']['detailItem'] ?? [] as $it) {
                    if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                        $itemNo = $it['item']['no'] ?? null;
                        break;
                    }
                }
            }
            if (! $itemNo) {
                throw new AccurateException("Gagal mengambil kode item Accurate dari PO Supplier {$s->nomor_po_supplier}");
            }
            // Gudang ikut PO baru (bisa beda depot/cabang dari source lama).
            $idCab = DB::table('vw_terminal_inventory_receive')->where('nomor_po_supplier', $s->nomor_po_supplier)->value('id_cabang');
            $gudang = $idCab ? DB::table('pro_master_cabang')->where('id_master', $idCab)->value('inisial_cabang') : null;
            if (! $gudang) {
                throw new AccurateException("Gagal mengambil gudang Accurate dari PO Supplier {$s->nomor_po_supplier}");
            }
            $perPorsi[] = ['itemNo' => $itemNo, 'volume' => $s->volume, 'warehouseName' => $gudang];
        }

        // Delivery Order lebih dulu.
        $do = $this->api->get('delivery-order/detail.do', ['id' => $acc->id_do_accurate]);
        if (empty($do['s'])) {
            throw new AccurateException('Gagal mengambil detail Delivery Order dari Accurate');
        }
        $saveDo = $this->api->post('delivery-order/save.do', [
            'id'         => $acc->id_do_accurate,
            'customerNo' => $acc->kode_pelanggan,
            'number'     => $acc->no_do_syop,
            'transDate'  => $do['d']['transDate'],
            'branchName' => $branch,
            'detailItem' => self::susun($do['d']['detailItem'] ?? [], $perPorsi, $branch),
        ]);
        if (empty($saveDo['s'])) {
            throw new AccurateException('Update Delivery Order Accurate gagal: '.($saveDo['d'][0] ?? 'unknown error'));
        }

        // Lalu Sales Order.
        $so = $this->api->get('sales-order/detail.do', ['id' => $acc->id_so_accurate]);
        if (empty($so['s'])) {
            throw new AccurateException('Gagal mengambil detail Sales Order dari Accurate');
        }
        $saveSo = $this->api->post('sales-order/save.do', [
            'id'         => $acc->id_so_accurate,
            'customerNo' => $acc->kode_pelanggan,
            'number'     => $acc->no_so,
            'transDate'  => $so['d']['transDate'],
            'branchName' => $branch,
            'detailItem' => self::susun($so['d']['detailItem'] ?? [], $perPorsi, $branch),
        ]);
        if (empty($saveSo['s'])) {
            throw new AccurateException('Update Sales Order Accurate gagal: '.($saveSo['d'][0] ?? 'unknown error'));
        }
    }

    /**
     * Susun ulang detailItem: item non-inventory tetap; baris inventory lama ditandai hapus eksplisit (_status: delete,
     * Accurate tidak mengizinkan ganti itemNo pada baris yang sama dan baris yang hanya dihilangkan tidak otomatis terhapus),
     * lalu satu baris baru per porsi dengan quantity = volume porsi itu.
     */
    public static function susun(array $lama, array $perPorsi, string $branch): array
    {
        $baru = [];
        $itemLama = null;
        foreach ($lama as $it) {
            if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                $itemLama = $it;
            } else {
                $baru[] = [
                    'id'             => $it['id'],
                    'itemNo'         => $it['item']['no'],
                    'quantity'       => $it['quantity'],
                    'unitPrice'      => $it['unitPrice'],
                    'departmentName' => $it['department']['name'] ?? null,
                ];
            }
        }
        if ($itemLama) {
            $baru[] = ['id' => $itemLama['id'], 'itemNo' => $itemLama['item']['no'], 'quantity' => $itemLama['quantity'], 'unitPrice' => $itemLama['unitPrice'], '_status' => 'delete'];
        }
        foreach ($perPorsi as $ip) {
            $baru[] = [
                'itemNo'         => $ip['itemNo'],
                'quantity'       => $ip['volume'],
                'unitPrice'      => $itemLama ? $itemLama['unitPrice'] : 0,
                'departmentName' => $itemLama ? ($itemLama['department']['name'] ?? $branch) : $branch,
                'warehouseName'  => $ip['warehouseName'],
            ];
        }

        return $baru;
    }
}
