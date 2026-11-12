<?php

namespace App\Services\Accurate;

/**
 * Persetujuan CEO untuk PO Supplier membuka kembali PO di Accurate (po-supplier.php, aksi approve peran 21):
 * baca purchase-order/detail.do lalu simpan ulang lewat purchase-order/save.do dengan manualClosed = false.
 */
class AccuratePoSync
{
    public function __construct(private AccurateClient $api)
    {
    }

    public function enabled(): bool
    {
        return $this->api->enabled();
    }

    /** @param  string|int|null  $idAccurate  new_pro_inventory_vendor_po.id_accurate */
    public function reopen($idAccurate, ?string $namaCabang): void
    {
        if (! $this->enabled()) {
            return;
        }
        if ($idAccurate === null || $idAccurate === '' || (int) $idAccurate === 0) {
            throw new AccurateException('Id accurate tidak ditemukan');
        }
        $detail = $this->api->get('purchase-order/detail.do', ['id' => $idAccurate]);
        if (empty($detail['s'])) {
            throw new AccurateException(($detail['d'][0] ?? 'Gagal membaca PO Accurate').' - Response dari Accurate');
        }
        $data = [
            'id' => $idAccurate,
            'branchName' => $namaCabang === 'Kantor Pusat' ? 'Head Office' : $namaCabang,
            'toAddress' => $detail['d']['toAddress'] ?? null,
            'manualClosed' => false,
        ];
        foreach ($detail['d']['detailItem'] ?? [] as $item) {
            $data['detailItem'][] = [
                'id' => $item['id'], 'itemNo' => $item['item']['no'], 'quantity' => $item['quantity'],
                'unitPrice' => $item['unitPrice'], 'manualClosed' => false,
            ];
        }
        $res = $this->api->post('purchase-order/save.do', $data);
        if (empty($res['s'])) {
            throw new AccurateException(($res['d'][0] ?? 'Gagal menyimpan PO Accurate').' - Response dari Accurate');
        }
    }
}
