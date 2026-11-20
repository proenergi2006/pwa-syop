<?php

namespace App\Services\Accurate;

/**
 * Loss/Gain PO Supplier ke Accurate. Sumber: web/action/vendor-po-new-loss-gain.php (aksi update).
 * Gain  : PO ditutup manual, RI lama dihapus, PO dibuat ulang (nomor segmen pertama diberi titik) dengan baris volume BL dan volume gain,
 *         RI dibuat ulang dari PO baru, lalu PO baru ditutup "Menunggu Approve".
 * Loss  : PO ditutup manual, lalu penyesuaian stok ADJUSTMENT_OUT (item-adjustment/save.do).
 * Beda dari web yang disengaja: hasil tiap panggilan benar-benar diperiksa (web memeriksa $result['d'], selalu benar, dan memaksa hasil hapus RI = sukses),
 * dan baris non-INVENTORY pada PO baru mempertahankan qty/harga aslinya.
 */
class AccurateLossGainSync
{
    public const ALAMAT = 'Graha Irama Lt 6 Jl. HR Rasuna Said Kota Administrasi Jakarta Selatan DKI Jakarta Indonesia';

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

    /** Pilihan akun penyesuaian (glaccount/list.do), seperti list-account-accurate.php. */
    public function akun(string $q, int $page): array
    {
        $query = ['fields' => 'id,no,nameWithIndent,accountTypeName,noWithIndent,name', 'sp.page' => $page, 'sp.pageSize' => 30];
        if ($q !== '') {
            $query['filter.keywords.op'] = 'CONTAIN';
            $query['filter.keywords.val'] = $q;
        }
        $r = $this->api->get('glaccount/list.do', $query);
        if (empty($r['s'])) {
            throw new AccurateException(($r['d'][0] ?? 'Gagal membaca akun').' - Response dari Accurate');
        }

        return [
            'items' => array_map(fn ($k) => ['id' => $k['no'], 'text' => html_entity_decode(($k['noWithIndent'] ?? '').($k['nameWithIndent'] ?? ''), ENT_QUOTES | ENT_HTML5)], $r['d'] ?? []),
            'total' => (int) ($r['sp']['rowCount'] ?? 0),
        ];
    }

    private function tutupPo($idAccurate, string $vendorNo, string $number, ?string $cabangUser, string $alasan, ?string $deskripsi = null): array
    {
        $data = ['id' => $idAccurate, 'vendorNo' => $vendorNo, 'number' => $number, 'branchName' => self::cabang($cabangUser),
            'toAddress' => self::ALAMAT, 'manualClosed' => true, 'closeReason' => $alasan];
        if ($deskripsi !== null) {
            $data['description'] = $deskripsi;
        }

        return $this->cek($this->api->post('purchase-order/save.do', $data), 'Gagal menutup PO');
    }

    /**
     * @param  array{po:object, vendorNo:string, receive:object, hargaRi:float, volume:float, pbbkb:float, pph22:float, ket:string, cabangUser:?string, namaCabangTerminal:?string}  $c
     * @return array{id_accurate_po:string, id_accurate_ri:string}
     */
    public function gain(array $c): array
    {
        $po = $c['po'];
        $this->tutupPo($po->id_accurate, $c['vendorNo'], $po->nomor_po, $c['cabangUser'], 'Close PO - gain', $c['ket']);

        if (! empty($c['receive']->id_accurate)) {
            $del = $this->api->delete('receive-item/delete.do', ['id' => $c['receive']->id_accurate]);
            $this->cek($del, 'Gagal menghapus RI');
        }

        $detail = $this->cek($this->api->get('purchase-order/detail.do', ['id' => $po->id_accurate]), 'Gagal membaca PO');
        $d = $detail['d'];
        $parts = explode('/', (string) $po->nomor_po);
        $parts[0] .= '.';
        $dept = $c['namaCabangTerminal'];
        $save = [
            'transDate' => date('d/m/Y', strtotime($po->tanggal_inven)), 'vendorNo' => $c['vendorNo'], 'number' => implode('/', $parts),
            'branchName' => self::cabang($c['cabangUser']), 'paymentTermName' => $po->terms === 'NET' ? $po->terms.' '.$po->terms_day : 'C.O.D',
            'charField1' => $po->id_terminal, 'charField2' => $po->kategori_plat, 'charField3' => $po->kd_tax, 'description' => $c['ket'],
            'toAddress' => self::ALAMAT, 'detailItem' => [], 'detailExpense' => [],
        ];
        foreach ($d['detailItem'] ?? [] as $it) {
            if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                $save['detailItem'][] = ['itemNo' => $it['item']['no'], 'quantity' => $c['receive']->volume_bol, 'unitPrice' => $c['hargaRi'],
                    'useTax1' => $it['useTax1'] ?? null, 'warehouseName' => $it['warehouse']['name'] ?? null, 'departmentName' => $dept];
                $save['detailItem'][] = ['itemNo' => $it['item']['no'], 'quantity' => $c['volume'], 'unitPrice' => $c['hargaRi'],
                    'useTax1' => $it['useTax1'] ?? null, 'warehouseName' => $it['warehouse']['name'] ?? null, 'departmentName' => $dept];
            } else {
                $save['detailItem'][] = ['itemNo' => $it['item']['no'], 'quantity' => $it['quantity'], 'unitPrice' => $it['unitPrice'],
                    'useTax1' => $it['useTax1'] ?? null, 'departmentName' => $dept];
            }
        }
        foreach ($d['detailExpense'] ?? [] as $ex) {
            $nama = (string) ($ex['expenseName'] ?? '');
            $jumlah = $nama === 'PBBKB' ? $c['pbbkb'] : (str_contains($nama, '22') ? $c['pph22'] : $ex['expenseAmount']);
            $save['detailExpense'][] = ['accountNo' => $ex['account']['no'], 'expenseAmount' => $jumlah, 'allocateToItemCost' => $ex['allocateToItemCost'], 'departmentName' => $dept];
        }
        $baru = $this->cek($this->api->post('purchase-order/save.do', $save), 'Gagal membuat PO baru')['r'];

        $ri = [
            'receiveNumber' => $c['receive']->no_terima, 'number' => $c['receive']->no_terima, 'transDate' => date('d/m/Y', strtotime($c['receive']->tgl_terima)),
            'vendorNo' => $baru['vendor']['vendorNo'] ?? $c['vendorNo'], 'description' => 'Terima barang dari PO '.($baru['number'] ?? $save['number']),
            'toAddress' => self::ALAMAT, 'branchName' => self::cabang($c['cabangUser']), 'detailItem' => [],
        ];
        $itemsBaru = $baru['detailItem'] ?? $this->cek($this->api->get('purchase-order/detail.do', ['id' => $baru['id']]), 'Gagal membaca PO baru')['d']['detailItem'];
        foreach ($itemsBaru as $it) {
            if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                $ri['detailItem'][] = ['itemNo' => $it['item']['no'], 'quantity' => $it['quantity'], 'useTax1' => $it['useTax1'] ?? null,
                    'purchaseOrderNumber' => $baru['number'] ?? $save['number'], 'departmentName' => $dept];
            }
        }
        $riBaru = $this->cek($this->api->post('receive-item/save.do', $ri), 'Gagal membuat RI')['r'];

        $this->tutupPo($baru['id'], $baru['vendor']['vendorNo'] ?? $c['vendorNo'], $baru['number'] ?? $save['number'], $c['cabangUser'], 'Menunggu Approve');

        return ['id_accurate_po' => (string) $baru['id'], 'id_accurate_ri' => (string) $riBaru['id']];
    }

    /** Tutup PO untuk loss, lalu kembalikan kode item INVENTORY PO (untuk penyesuaian). */
    public function lossTutup(object $po, string $vendorNo, ?string $cabangUser, string $ket): ?string
    {
        $this->tutupPo($po->id_accurate, $vendorNo, $po->nomor_po, $cabangUser, 'Close PO - loss', $ket);
        $r = $this->api->get('purchase-order/detail.do', ['id' => $po->id_accurate]);
        foreach ($r['d']['detailItem'] ?? [] as $it) {
            if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                return $it['item']['no'];
            }
        }

        return null;
    }

    /** @return string id penyesuaian di Accurate */
    public function penyesuaian(string $nomor, string $akun, string $deskripsi, ?string $cabangUser, string $itemNo, float $qty, ?string $gudang, ?string $dept): string
    {
        $r = $this->api->post('item-adjustment/save.do', [
            'number' => $nomor, 'adjustmentAccountNo' => $akun, 'transDate' => date('d/m/Y'), 'description' => $deskripsi,
            'branchName' => self::cabang($cabangUser),
            'detailItem' => [['itemAdjustmentType' => 'ADJUSTMENT_OUT', 'itemNo' => $itemNo, 'quantity' => $qty, 'warehouseName' => $gudang, 'departmentName' => $dept]],
        ]);
        if (empty($r['s'])) {
            throw new AccurateException(($r['d'][0] ?? 'Gagal menyimpan penyesuaian').' - Response dari Accurate (adjustment stock)');
        }

        return (string) $r['r']['id'];
    }
}
