<?php

namespace App\Services\Accurate;

/**
 * Simpan PO Supplier ke Accurate (purchase-order/save.do). Sumber: web/action/vendor-po-new.php (aksi add dan update).
 * Tambah: PO dibuat lalu ditutup manual "Menunggu Approve" sampai disetujui CEO (persetujuan CEO membukanya, lihat AccuratePoSync).
 * Ubah  : PO lama dihapus di Accurate lalu dibuat ulang dengan nomor yang sama, kembali ditutup "Menunggu Approve".
 */
class AccuratePoSupplierSync
{
    public const ALAMAT = AccurateLossGainSync::ALAMAT;

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

    private function cek(array $res, string $default): array
    {
        if (empty($res['s'])) {
            throw new AccurateException(($res['d'][0] ?? $default).' - Response dari Accurate');
        }

        return $res;
    }

    /** Pilihan item (item/list.do) seperti list-product-accurate.php. */
    public function barang(string $q, int $page): array
    {
        $query = ['fields' => 'id,no,name', 'filter.itemType.val' => ['INVENTORY', 'NON_INVENTORY', 'SERVICE'], 'sp.page' => $page, 'sp.pageSize' => 30];
        if ($q !== '') {
            $query['filter.keywords.op'] = 'CONTAIN';
            $query['filter.keywords.val'] = $q;
        }
        $r = $this->cek($this->api->get('item/list.do', $query), 'Gagal membaca item');

        return ['items' => array_map(fn ($i) => ['id' => $i['no'], 'text' => $i['no'].' ( '.html_entity_decode($i['name'] ?? '', ENT_QUOTES | ENT_HTML5).' ) '], $r['d'] ?? []), 'total' => (int) ($r['sp']['rowCount'] ?? 0)];
    }

    /** Isi awal form ubah dari PO di Accurate: kode item, item OA, dan akun biaya (pemetaan nama sama dengan web). */
    public function prefill($idAccurate): array
    {
        $r = $this->cek($this->api->get('purchase-order/detail.do', ['id' => $idAccurate]), 'Gagal membaca PO Accurate')['d'];
        $o = ['kode_item' => null, 'nama_item' => null, 'catatan_item' => null, 'kode_oa' => null, 'nama_oa' => null, 'catatan_oa' => null, 'biaya' => []];
        foreach ($r['detailItem'] ?? [] as $it) {
            if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                $o['kode_item'] = $it['item']['no'];
                $o['nama_item'] = $it['item']['name'] ?? null;
                $o['catatan_item'] = $it['detailNotes'] ?? null;
            } else {
                $o['kode_oa'] = $it['item']['no'];
                $o['nama_oa'] = $it['item']['name'] ?? null;
                $o['catatan_oa'] = $it['detailNotes'] ?? null;
            }
        }
        foreach ($r['detailExpense'] ?? [] as $ex) {
            $name = (string) ($ex['expenseName'] ?? '');
            $notes = $ex['expenseNotes'] ?? null;
            $row = ['akun' => $ex['account']['no'] ?? null, 'nama' => $name, 'catatan' => $notes, 'alokasi' => (bool) ($ex['allocateToItemCost'] ?? false), 'jumlah' => $ex['expenseAmount'] ?? null];
            if ($name === 'PBBKB' && in_array($notes, ['null', null, 'NULL'], true)) {
                $row['catatan'] = '';
                $o['biaya']['pbbkb'] = $row;
            } elseif (str_contains($name, '22')) {
                $o['biaya']['pph22'] = $row;
            } elseif (str_contains($name, 'VAT')) {
                $o['biaya']['lain'] = $row;
            } elseif (stripos((string) $notes, 'iuran') !== false || stripos($name, 'iuran') !== false) {
                $o['biaya']['migas'] = $row;
            } elseif (stripos($name, 'cost') !== false) {
                $o['biaya']['oa'] = $row;
            }
        }

        return $o;
    }

    /** Badan permintaan save.do dari data form yang sudah divalidasi. */
    private function badan(array $p): array
    {
        $dept = $p['departemen'];
        $data = [
            'transDate' => date('d/m/Y', strtotime($p['tanggal'])), 'vendorNo' => $p['vendorNo'], 'number' => $p['nomor'],
            'branchName' => self::cabang($p['cabangUser']), 'paymentTermName' => $p['terms'] === 'NET' ? 'NET '.$p['terms_day'] : 'C.O.D',
            'charField1' => $p['terminal'], 'charField2' => $p['plat'], 'charField3' => $p['kd_tax'], 'description' => $p['ket'],
            'toAddress' => self::ALAMAT, 'detailItem' => [], 'detailExpense' => [],
        ];
        foreach ($p['items'] as $it) {
            $data['detailItem'][] = ['itemNo' => $it['no'], 'quantity' => $it['qty'], 'unitPrice' => $it['harga'], 'useTax1' => $it['pajak'],
                'detailNotes' => $it['catatan'], 'departmentName' => $dept, 'warehouseName' => $p['gudang']];
        }
        foreach ($p['biaya'] as $b) {
            $data['detailExpense'][] = ['accountNo' => $b['akun'], 'expenseAmount' => $b['jumlah'], 'allocateToItemCost' => $b['alokasi'], 'expenseNotes' => $b['catatan'], 'departmentName' => $dept];
        }

        return $data;
    }

    private function simpanDanTutup(array $p, ?array $tambah = null): string
    {
        $save = $this->cek($this->api->post('purchase-order/save.do', $this->badan($p)), 'Gagal menyimpan PO');
        $id = $save['r']['id'];
        $this->cek($this->api->post('purchase-order/save.do', [
            'id' => $id, 'toAddress' => self::ALAMAT, 'branchName' => self::cabang($p['cabangUser']), 'manualClosed' => true, 'closeReason' => 'Menunggu Approve',
        ]), 'Gagal menutup PO');

        return (string) $id;
    }

    public function buat(array $p): string
    {
        return $this->simpanDanTutup($p);
    }

    /** Hapus PO lama lalu buat ulang. */
    public function ubah($idAccurateLama, array $p): string
    {
        $this->cek($this->api->delete('purchase-order/delete.do', ['id' => $idAccurateLama]), 'Gagal menghapus PO lama');

        return $this->simpanDanTutup($p);
    }

    /**
     * Perubahan harga PO yang sudah ada penerimaan (aksi perubahan): PO Accurate yang sama disimpan ulang dengan harga satuan baru
     * (INVENTORY = harga dasar, lainnya = ongkos angkut) dan jumlah biaya dari akun yang dipilih. Seperti web, PO tidak ditutup ulang.
     *
     * @param  array{tanggal:string, vendorNo:string, cabangUser:?string, ket:string, harga:float, ongkos:float, departemen:?string, biaya:array<string,array{jumlah:mixed,catatan:string}>}  $p
     */
    public function ubahHarga($idAccurate, array $p): void
    {
        $d = $this->cek($this->api->get('purchase-order/detail.do', ['id' => $idAccurate]), 'Gagal membaca PO Accurate')['d'];
        $dept = $p['departemen'];
        $items = [];
        foreach ($d['detailItem'] ?? [] as $it) {
            $inv = ($it['item']['itemType'] ?? null) === 'INVENTORY';
            $items[] = ['id' => $it['id'], 'itemNo' => $it['item']['no'], 'quantity' => $it['quantity'], 'unitPrice' => $inv ? $p['harga'] : $p['ongkos'],
                'warehouseName' => $it['warehouse']['name'] ?? null, 'departmentName' => $it['department']['name'] ?? $dept];
        }
        $expenses = [];
        foreach ($d['detailExpense'] ?? [] as $ex) {
            $akun = $ex['account']['no'];
            $expenses[] = ['id' => $ex['id'], 'accountNo' => $akun, 'expenseAmount' => $p['biaya'][$akun]['jumlah'] ?? $ex['expenseAmount'],
                'allocateToItemCost' => $ex['allocateToItemCost'], 'expenseNotes' => $p['biaya'][$akun]['catatan'] ?? ($ex['expenseNotes'] ?? ''), 'departmentName' => $dept];
        }
        $this->cek($this->api->post('purchase-order/save.do', [
            'id' => $idAccurate, 'transDate' => date('d/m/Y', strtotime($p['tanggal'])), 'vendorNo' => $p['vendorNo'], 'number' => $d['number'],
            'branchName' => self::cabang($p['cabangUser']), 'description' => $p['ket'], 'toAddress' => self::ALAMAT, 'detailItem' => $items, 'detailExpense' => $expenses,
        ]), 'Gagal menyimpan PO');
    }

    /** Close PO: tutup manual di Accurate dengan alasan "CLOSE PO dd/mm/yyyy - volume close = N" (aksi close di web). */
    public function tutup($idAccurate, string $toAddress, ?string $cabangUser, string $alasan): void
    {
        $this->cek($this->api->post('purchase-order/save.do', [
            'id' => $idAccurate, 'toAddress' => $toAddress, 'branchName' => self::cabang($cabangUser), 'manualClosed' => true, 'closeReason' => $alasan,
        ]), 'Gagal menutup PO');
    }
}
