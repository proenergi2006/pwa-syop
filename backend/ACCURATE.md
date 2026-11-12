# Integrasi Accurate Online

Setara dengan `curl.php` aplikasi web lama. **Nonaktif secara bawaan**; tidak ada panggilan ke Accurate sampai diaktifkan di server.

## Mengaktifkan (di server live, file `.env`)
```
ACCURATE_ENABLED=true
ACCURATE_TOKEN=<Bearer token API Accurate>
ACCURATE_SIGNATURE_SECRET=<kunci tanda tangan HMAC>
```
Lalu `php artisan config:clear` (atau `config:cache`). Jangan menaruh token/secret di kode atau repositori.

## Yang dipasang
| Alur PWA | Panggilan Accurate |
|---|---|
| Delivery Request: Simpan (Purchasing) | `purchase-order/detail.do` (item inventory), `sales-order/save.do`, `delivery-order/save.do` per customer + rencana kirim |
| Delivery Request: Revisi DR | `delivery-order/delete.do`, lalu `sales-order/delete.do` |
| PO Supplier: setuju CEO | `purchase-order/detail.do`, `purchase-order/save.do` (manualClosed = false) |

Hanya berjalan bila PO Supplier yang dipilih punya `id_accurate`. Bila langkah gagal: Simpan DR dan Setuju PO dibatalkan (database kembali), dokumen Accurate yang sudah terbuat pada proses itu dihapus lagi; Revisi DR berhenti tanpa mengubah database.
Batas Accurate (8 request/detik dan 8 paralel per token) dijaga oleh `AccurateClient::batch()`.

## Belum dipasang
Trucking Instruction (`item-adjustment/*`, bagian Receive peran 5), dan sinkron Sales Order untuk harga baru pada penawaran.

## Pengujian
Semua tes memakai `Http::fake` (tanpa jaringan). Jangan menguji ke Accurate produksi: Sales Order/Delivery Order yang terbentuk tidak bisa dibatalkan begitu saja.
