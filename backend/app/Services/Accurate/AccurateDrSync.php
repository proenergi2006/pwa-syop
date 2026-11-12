<?php

namespace App\Services\Accurate;

use Illuminate\Support\Facades\DB;

/**
 * Sinkron Delivery Request ke Accurate, setara dengan bagian Accurate pada purchase-request (2).php (peran 5):
 *   Simpan   -> Sales Order (sales-order/save.do) lalu Delivery Order (delivery-order/save.do) per customer + rencana kirim (plan)
 *   Revisi   -> hapus Delivery Order lalu Sales Order
 * Hanya berjalan bila PO Supplier yang dipilih tercatat di Accurate (new_pro_inventory_vendor_po.id_accurate) dan klien aktif.
 * Berbeda dari web: bila salah satu langkah gagal, dokumen Accurate yang sudah terlanjur dibuat pada proses ini ikut dihapus,
 * dan plan yang sudah punya Sales Order tidak dibuat ulang.
 */
class AccurateDrSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    private static function branch(?string $nama): ?string
    {
        return $nama === 'Kantor Pusat' ? 'Head Office' : $nama;
    }

    /** Detail item PO Accurate (di-cache per id selama satu proses). */
    private function poItem(string|int $idAccurate, array &$cache): ?string
    {
        if (! array_key_exists($idAccurate, $cache)) {
            $r = $this->api->get('purchase-order/detail.do', ['id' => $idAccurate]);
            if (empty($r['s'])) {
                throw new AccurateException(($r['d'][0] ?? 'Gagal membaca PO Accurate').' - Response dari Accurate PO');
            }
            $no = null;
            foreach ($r['d']['detailItem'] ?? [] as $it) {
                if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                    $no = $it['item']['no'] ?? null;
                    break;
                }
            }
            $cache[$idAccurate] = $no;
        }

        return $cache[$idAccurate];
    }

    /** @return array{po_accurate:?int, cabang:?object} PO Supplier (berdasarkan nomor) dan cabang gudangnya. */
    private function gudang(string $nomorPo): array
    {
        $po = DB::table('new_pro_inventory_vendor_po')->where('nomor_po', $nomorPo)->first(['id_accurate']);
        $vw = DB::table('vw_terminal_inventory_receive')->where('nomor_po_supplier', $nomorPo)->first(['id_cabang']);
        $cab = $vw ? DB::table('pro_master_cabang')->where('id_master', $vw->id_cabang)->first(['nama_cabang', 'inisial_cabang']) : null;

        return ['po_accurate' => $po?->id_accurate, 'cabang' => $cab];
    }

    private static function rincian(?string $json): array
    {
        $out = ['Harga Dasar' => 0, 'Ongkos Angkut' => 0, 'PBBKB' => 0, 'PPN' => 0];
        foreach (json_decode((string) $json, true) ?: [] as $r) {
            if (array_key_exists($r['rincian'] ?? '', $out)) {
                $out[$r['rincian']] = $r['biaya'] ?? 0;
            }
        }

        return $out;
    }

    /** Buat SO dan DO di Accurate untuk DR ini. Melempar AccurateException bila gagal (dokumen yang sudah terbuat dihapus lagi). */
    public function create(int $idPr, string $summary = ''): void
    {
        if (! $this->enabled()) {
            return;
        }
        $lines = DB::select("
            select a.id_prd, a.id_plan, a.volume, a.vol_ori_pr, a.no_do_syop, a.nomor_po_supplier,
                   k.gabung_oa, k.gabung_pbbkb, k.gabung_pbbkboa, k.all_in, k.detail_rincian, k.id_cabang as id_cabang_penawaran,
                   h.kode_pelanggan, c.created_by as plan_by, c.id_accurate as so_accurate
            from pro_pr_detail a
                join pro_po_customer_plan c on a.id_plan = c.id_plan
                join pro_po_customer d on c.id_poc = d.id_poc
                join pro_customer h on d.id_customer = h.id_customer
                join pro_penawaran k on d.id_penawaran = k.id_penawaran
            where a.id_pr = ? and a.is_approved = 1
            order by c.tanggal_kirim, k.id_cabang, k.id_area, a.id_plan, a.id_prd", [$idPr]);

        $cache = [];
        $groups = []; // [kode][plan] => ['items'=>[], 'nomor_do'=>, 'id_prd'=>, 'harga'=>, 'cab'=>id_cabang_penawaran, 'skip'=>bool]
        foreach ($lines as $x) {
            $g = &$groups[$x->kode_pelanggan][$x->id_plan];
            $g ??= ['items' => [], 'nomor_do' => null, 'id_prd' => null, 'harga' => null, 'cab' => $x->id_cabang_penawaran, 'skip' => (bool) $x->so_accurate, 'has_accurate' => false];
            $gd = $this->gudang((string) $x->nomor_po_supplier);
            $g['nomor_do'] = $x->no_do_syop;
            $g['id_prd'] = $x->id_prd;
            if ($gd['po_accurate'] && $gd['cabang']) {
                $g['has_accurate'] = true;
                $inv = $this->poItem($gd['po_accurate'], $cache);
                $sales = DB::table('acl_user')->where('fullname', $x->plan_by)->value('kode_accurate');
                $dept = $gd['cabang']->nama_cabang;
                $q = (int) ($x->vol_ori_pr ?: $x->volume);
                $v = self::rincian($x->detail_rincian);
                $ppn = ['itemNo' => 'NS-009', 'unitPrice' => $v['PPN'], 'quantity' => $q, 'salesmanListNumber' => $sales, 'departmentName' => $dept, 'detailNotes' => 'Pajak Pertambahan Nilai', 'useTax1' => false];
                $pbbkb = ['itemNo' => 'PBBKB', 'unitPrice' => $v['PBBKB'], 'quantity' => $q, 'salesmanListNumber' => $sales, 'departmentName' => $dept];
                $oa = ['itemNo' => 'NS-001', 'unitPrice' => $v['Ongkos Angkut'], 'quantity' => $q, 'salesmanListNumber' => $sales, 'departmentName' => $dept, 'useTax1' => false];
                $jenis = ($x->all_in || (int) $x->gabung_oa === 1) ? 'gabung_oa' : ((int) $x->gabung_pbbkb === 1 ? 'gabung_pbbkb' : ((int) $x->gabung_pbbkboa === 1 ? 'all_in' : 'break_all'));
                // Sama dengan web: tiga jenis pertama MENGGANTI daftar item plan, jenis lain menambah.
                if ($jenis === 'gabung_oa') {
                    $g['items'] = [$ppn, $pbbkb];
                    $harga = $v['Harga Dasar'] + $v['Ongkos Angkut'];
                } elseif ($jenis === 'gabung_pbbkb') {
                    $g['items'] = [$ppn, $oa];
                    $harga = $v['Harga Dasar'] + $v['PBBKB'];
                } elseif ($jenis === 'break_all') {
                    $g['items'] = [$ppn, $pbbkb, $oa];
                    $harga = $v['Harga Dasar'];
                } else {
                    $g['items'][] = $ppn;
                    $harga = $v['Harga Dasar'] + $v['PBBKB'] + $v['Ongkos Angkut'];
                }
                $g['harga'] = $harga;
                if ($inv) {
                    $g['items'][] = ['itemNo' => $inv, 'quantity' => (int) $x->volume, 'unitPrice' => $harga, 'warehouseName' => $gd['cabang']->inisial_cabang,
                        'salesmanListNumber' => $sales, 'departmentName' => $dept, 'useTax1' => false];
                }
                $g['sales'] = $sales;
            }
            unset($g);
        }

        // Bagian split (new_pro_inventory_potongan_stock): item tambahan pada plan yang sama.
        foreach (DB::table('new_pro_inventory_potongan_stock')->where('id_pr', $idPr)->get(['id_prd', 'volume', 'nomor_po_supplier']) as $sp) {
            $plan = DB::table('pro_pr_detail')->where('id_prd', $sp->id_prd)->value('id_plan');
            $kode = DB::selectOne('select h.kode_pelanggan from pro_po_customer_plan c join pro_po_customer d on c.id_poc = d.id_poc
                join pro_customer h on d.id_customer = h.id_customer where c.id_plan = ?', [$plan])->kode_pelanggan ?? null;
            if (! isset($groups[$kode][$plan])) {
                continue;
            }
            $g = &$groups[$kode][$plan];
            $gd = $this->gudang((string) $sp->nomor_po_supplier);
            if ($gd['po_accurate'] && $gd['cabang']) {
                $inv = $this->poItem($gd['po_accurate'], $cache);
                if ($inv) {
                    $g['items'][] = ['itemNo' => $inv, 'quantity' => (int) $sp->volume, 'unitPrice' => $g['harga'], 'warehouseName' => $gd['cabang']->inisial_cabang,
                        'salesmanListNumber' => $g['sales'] ?? null, 'departmentName' => $gd['cabang']->nama_cabang, 'useTax1' => false];
                }
            }
            unset($g);
        }

        $createdSo = [];
        $createdDo = [];
        $undo = function () use (&$createdSo, &$createdDo) {
            foreach (array_reverse($createdDo) as $id) {
                $this->api->delete('delivery-order/delete.do', ['id' => $id]);
            }
            foreach (array_reverse($createdSo) as $id) {
                $this->api->delete('sales-order/delete.do', ['id' => $id]);
            }
        };

        try {
            foreach ($groups as $kode => $plans) {
                foreach ($plans as $planId => $g) {
                    if ($g['skip'] || ! $g['has_accurate']) {
                        continue; // plan sudah punya SO, atau PO Supplier tidak tercatat di Accurate
                    }
                    $po = DB::selectOne("
                        select a.no_so, a.tanggal_kirim, a.tanggal_loading, a.id_lcr, b.nomor_poc, c.alamat_customer, c.postalcode_customer,
                               d.nama_prov, e.nama_kab, f.jangka_waktu as jangka_waktu_pn, f.jenis_payment as jenis_payment_pn
                        from pro_po_customer_plan a
                            join pro_po_customer b on a.id_poc = b.id_poc
                            join pro_customer c on b.id_customer = c.id_customer
                            join pro_master_provinsi d on c.prov_customer = d.id_prov
                            join pro_master_kabupaten e on c.kab_customer = e.id_kab
                            join pro_penawaran f on b.id_penawaran = f.id_penawaran
                        where a.id_plan = ?", [$planId]);
                    $lcr = DB::selectOne('select a.alamat_survey, b.nama_prov, c.nama_kab from pro_customer_lcr a
                        join pro_master_provinsi b on a.prov_survey = b.id_prov join pro_master_kabupaten c on a.kab_survey = c.id_kab where a.id_lcr = ?', [$po->id_lcr]);
                    $cab = DB::table('pro_master_cabang')->where('id_master', $g['cab'])->value('nama_cabang');
                    $alamat = $po->alamat_customer.' '.$po->nama_prov.' '.$po->nama_kab.' Kode Pos : '.$po->postalcode_customer;
                    $site = ($lcr->alamat_survey ?? '').' '.($lcr->nama_prov ?? '').' '.($lcr->nama_kab ?? '');
                    $payment = $po->jenis_payment_pn === 'COD' ? 'C.O.D' : ($po->jenis_payment_pn === 'CBD' ? 'C.B.D' : 'net '.$po->jangka_waktu_pn);

                    $so = $this->api->post('sales-order/save.do', [
                        'customerNo' => (string) $kode, 'number' => $po->no_so, 'toAddress' => $alamat, 'description' => 'SO dari PO '.$po->nomor_poc,
                        'poNumber' => $po->nomor_poc, 'transDate' => date('d/m/Y'), 'shipDate' => date('d/m/Y', strtotime($po->tanggal_kirim)),
                        'taxable' => false, 'paymentTermName' => $payment, 'branchName' => self::branch($cab), 'detailItem' => $g['items'],
                    ]);
                    if (empty($so['s'])) {
                        throw new AccurateException(($so['d'][0] ?? 'Gagal membuat Sales Order').' - Response dari Accurate SO');
                    }
                    $soId = $so['r']['id'];
                    $createdSo[] = $soId;
                    DB::table('pro_po_customer_plan')->where('id_plan', $planId)->update(['id_accurate' => $soId]);

                    $detail = [];
                    foreach ($g['items'] as $it) {
                        $d = ['itemNo' => $it['itemNo'], 'quantity' => $it['quantity'], 'salesOrderNumber' => $po->no_so, 'departmentName' => $it['departmentName']];
                        if ($it['itemNo'] === 'NS-009') {
                            $d['detailNotes'] = 'Pajak Pertumbuhan Nilai'; // ejaan sama dengan web
                        }
                        if (isset($it['warehouseName'])) {
                            $d['warehouseName'] = $it['warehouseName'];
                        }
                        $detail[] = $d;
                    }
                    $do = $this->api->post('delivery-order/save.do', [
                        'customerNo' => (string) $kode, 'number' => $g['nomor_do'], 'description' => trim('DO dari PO '.$po->nomor_poc.' '.$summary),
                        'poNumber' => $po->nomor_poc, 'toAddress' => $site, 'transDate' => date('d/m/Y', strtotime($po->tanggal_loading)),
                        'branchName' => self::branch($cab), 'detailItem' => $detail,
                    ]);
                    if (empty($do['s'])) {
                        throw new AccurateException(($do['d'][0] ?? 'Gagal membuat Delivery Order').' - Response dari Accurate DO');
                    }
                    $createdDo[] = $do['r']['id'];
                    DB::table('pro_pr_detail')->where('id_prd', $g['id_prd'])->update(['id_do_accurate' => $do['r']['id']]);
                }
            }
        } catch (\Throwable $e) {
            $undo();
            throw $e instanceof AccurateException ? $e : new AccurateException($e->getMessage(), 0, $e);
        }
    }

    /** Hapus DO lalu SO di Accurate (Revisi DR). Melempar AccurateException bila Accurate menolak. */
    public function delete(int $idPr): void
    {
        if (! $this->enabled()) {
            return;
        }
        $rows = DB::table('pro_pr_detail')->where('id_pr', $idPr)->whereNotNull('id_do_accurate')->where('id_do_accurate', '<>', 0)->get(['id_prd', 'id_plan', 'id_do_accurate']);
        foreach ($rows as $r) {
            $d = $this->api->delete('delivery-order/delete.do', ['id' => $r->id_do_accurate]);
            if (empty($d['s'])) {
                throw new AccurateException(($d['d'][0] ?? 'Gagal menghapus Delivery Order').' - Response dari Accurate DO');
            }
            DB::table('pro_pr_detail')->where('id_prd', $r->id_prd)->update(['id_do_accurate' => null]);
            $so = DB::table('pro_po_customer_plan')->where('id_plan', $r->id_plan)->value('id_accurate');
            if ($so) {
                $s = $this->api->delete('sales-order/delete.do', ['id' => $so]);
                if (empty($s['s'])) {
                    throw new AccurateException(($s['d'][0] ?? 'Gagal menghapus Sales Order').' - Response dari Accurate SO');
                }
                DB::table('pro_po_customer_plan')->where('id_plan', $r->id_plan)->update(['id_accurate' => null]);
            }
        }
    }
}
