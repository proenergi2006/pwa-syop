<?php

namespace App\Services\Accurate;

/**
 * Penerimaan barang (RI) PO Supplier ke Accurate: receive-item/save.do dan delete.do.
 * Sumber: web/action/vendor-po-new-terima.php (aksi add, update, hapus). Pesan galat mengikuti web: "<pesan Accurate> - Response dari Accurate".
 */
class AccurateRiSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    private static function cabang(?string $nama): ?string
    {
        return $nama === 'Kantor Pusat' ? 'Head Office' : $nama;
    }

    private function gagal(array $res, string $default): never
    {
        throw new AccurateException(($res['d'][0] ?? $default).' - Response dari Accurate');
    }

    /** Detail PO di Accurate; bila tidak ada, pesan sama dengan web. */
    private function poDetail($idAccuratePo): array
    {
        $r = $this->api->get('purchase-order/detail.do', ['id' => $idAccuratePo]);
        if (empty($r['s'])) {
            throw new AccurateException('PO Supplier pada accurate tidak ada');
        }

        return $r['d'];
    }

    /** Kode item dan harga satuan dari baris pertama PO di Accurate (dipakai form terima). */
    public function itemPo($idAccuratePo): array
    {
        $d = $this->poDetail($idAccuratePo);

        return ['item' => $d['detailItem'][0]['item']['no'] ?? null, 'harga' => $d['detailItem'][0]['unitPrice'] ?? null, 'detail' => $d];
    }

    /** @return string id RI di Accurate */
    public function buat(object $po, string $noTerima, string $tgl, float $qty, ?string $cabangUser, ?object $terminalCabang): string
    {
        $d = $this->poDetail($po->id_accurate);
        $res = $this->api->post('receive-item/save.do', [
            'receiveNumber' => $noTerima, 'number' => $noTerima, 'transDate' => $tgl,
            'vendorNo' => $d['vendor']['vendorNo'] ?? null, 'description' => 'Terima barang dari PO '.$po->nomor_po,
            'toAddress' => $d['toAddress'] ?? null, 'branchName' => self::cabang($cabangUser),
            'detailItem' => [[
                'itemNo' => $d['detailItem'][0]['item']['no'] ?? null, 'quantity' => $qty, 'unitPrice' => $d['detailItem'][0]['unitPrice'] ?? null,
                'purchaseOrderNumber' => $po->nomor_po, 'warehouseName' => $terminalCabang->inisial_cabang ?? null,
                'departmentName' => $terminalCabang->nama_cabang ?? null,
            ]],
        ]);
        if (empty($res['s'])) {
            $this->gagal($res, 'Gagal menyimpan RI');
        }

        return (string) $res['r']['id'];
    }

    /** Ubah RI. Bila RI belum ada di Accurate, dibuat baru dengan qty = volume terima (perilaku web). @return string id RI di Accurate */
    public function ubah(object $po, object $ri, string $noTerima, string $tgl, float $volBol, float $volTerima, ?string $cabangUser, ?object $terminalCabang): string
    {
        $detailRi = ! empty($ri->id_accurate) ? $this->api->get('receive-item/detail.do', ['id' => $ri->id_accurate]) : ['s' => false];
        $d = $this->poDetail($po->id_accurate);
        $item = $d['detailItem'][0]['item']['no'] ?? null;
        $harga = $d['detailItem'][0]['unitPrice'] ?? null;
        $data = [
            'receiveNumber' => $noTerima, 'number' => $noTerima, 'transDate' => $tgl,
            'vendorNo' => $d['vendor']['vendorNo'] ?? null, 'description' => 'Terima barang dari PO '.$po->nomor_po,
            'toAddress' => $d['toAddress'] ?? null, 'branchName' => self::cabang($cabangUser),
        ];
        if (! empty($detailRi['s'])) {
            $data['id'] = $ri->id_accurate;
            $data['detailItem'] = [];
            foreach ($detailRi['d']['detailItem'] ?? [] as $it) {
                if (($it['item']['itemType'] ?? null) === 'INVENTORY') {
                    $data['detailItem'][] = [
                        'id' => $it['id'], 'itemNo' => $item, 'quantity' => $volBol, 'unitPrice' => $harga,
                        'purchaseOrderNumber' => $po->nomor_po, 'warehouseName' => $terminalCabang->inisial_cabang ?? null,
                        'departmentName' => $terminalCabang->nama_cabang ?? null,
                    ];
                }
            }
        } else {
            $data['detailItem'] = [[
                'itemNo' => $item, 'quantity' => $volTerima, 'unitPrice' => $harga,
                'purchaseOrderNumber' => $po->nomor_po, 'warehouseName' => $terminalCabang->inisial_cabang ?? null,
            ]];
        }
        $res = $this->api->post('receive-item/save.do', $data);
        if (empty($res['s'])) {
            $this->gagal($res, 'Gagal menyimpan RI');
        }

        return (string) $res['r']['id'];
    }

    public function hapus($idAccurate): void
    {
        if (empty($idAccurate)) {
            return;
        }
        $res = $this->api->delete('receive-item/delete.do', ['id' => $idAccurate]);
        if (empty($res['s'])) {
            $this->gagal($res, 'Gagal menghapus RI');
        }
    }
}
