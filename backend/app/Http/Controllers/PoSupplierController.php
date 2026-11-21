<?php

namespace App\Http\Controllers;

use App\Support\ParamCrypt;
use App\Support\Terbilang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PO Supplier (Procurement). Sumber: vendor-po-new.php (daftar), datatable vendor-po-new (1).php (kolom dan tombol aksi),
 * vendor-po-new-add.php (detail), po-supplier-vendor-syop.php dan po-supplier-vendor-syop1.php (cetakan).
 * Tahap ini: daftar, detail, dan data cetakan. Tambah/ubah, terima barang, close dan cancel menyusul.
 */
class PoSupplierController extends Controller
{
    private const PO = 'new_pro_inventory_vendor_po';

    private function base()
    {
        return DB::table(self::PO.' as a')
            ->join('pro_master_produk as c', 'a.id_produk', '=', 'c.id_master')
            ->join('pro_master_vendor as d', 'a.id_vendor', '=', 'd.id_master')
            ->join('pro_master_terminal as e', 'a.id_terminal', '=', 'e.id_master');
    }

    private static function terminal($r): string
    {
        return trim($r->nama_terminal.($r->tanki_terminal ? ' - '.$r->tanki_terminal : '').($r->lokasi_terminal ? ', '.$r->lokasi_terminal : ''));
    }

    /** Pilihan filter: terminal aktif dan vendor aktif (sama dengan kotak pilih di web). */
    public function opsi(): JsonResponse
    {
        return response()->json([
            'terminal' => DB::table('pro_master_terminal')->where('is_active', 1)->orderBy('id_master')
                ->get(['id_master', 'nama_terminal', 'tanki_terminal', 'lokasi_terminal'])
                ->map(fn ($t) => ['id' => (int) $t->id_master, 'label' => trim($t->nama_terminal.' - '.$t->tanki_terminal.' - '.$t->lokasi_terminal)])->values(),
            'vendor' => DB::table('pro_master_vendor')->where('is_active', 1)->orderBy('id_master')
                ->get(['id_master', 'nama_vendor'])->map(fn ($v) => ['id' => (int) $v->id_master, 'label' => $v->nama_vendor])->values(),
        ]);
    }

    /** Teks disposisi persis seperti kolom Disposisi di web. */
    private static function status($p): string
    {
        $tgl = fn ($t) => $t ? date('d/m/Y H:i:s', strtotime($t)).' WIB' : '';

        return match ((int) $p->disposisi_po) {
            2 => 'Verifikasi CEO',
            1 => 'Verifikasi CFO',
            3 => 'Ditolak CFO|'.$tgl($p->cfo_tanggal),
            5 => 'Ditolak CEO|'.$tgl($p->ceo_tanggal),
            4 => 'Terverifikasi|'.$tgl($p->ceo_tanggal),
            default => '',
        };
    }

    /** Tombol aksi per baris untuk Procurement (peran 5), mengikuti urutan dan syarat di datatable web. */
    public static function aksi($p, ?object $ship, float $volTerima): array
    {
        $out = [];
        $add = function (string $k) use (&$out) {
            if (! in_array($k, $out, true)) {
                $out[] = $k;
            }
        };
        $ceo1 = (int) $p->ceo_result === 1;
        $revert = (int) ($p->revert_ceo ?? 0) === 1;
        $kapal = (int) $p->jenis_kirim === 2;
        if ((int) $p->is_cancel !== 1 && (int) $p->is_close !== 1) {
            if (! $ceo1 && $volTerima == 0) {
                $add('edit');
            }
            if ($ceo1 && $revert) {
                $add('edit');
            }
            if ($ceo1 && ! $revert) {
                if ($volTerima == 0 && (int) $p->resubmission_count < 3) {
                    $add('edit');
                    if ($kapal) {
                        if ($ship && (int) $ship->ceo_result === 1) {
                            $add('terima');
                        }
                        $add('ship');
                    } else {
                        $add('terima');
                    }
                } else {
                    $add('terima');
                    if ($kapal) {
                        $add('ship');
                    }
                }
                if ($volTerima != 0) {
                    $add('lihat');
                }
                $add('cetak');
                $add('close');
            }
        } else {
            $add('lihat');
            if ($volTerima > 0) {
                $add('ri');
            }
            if ($kapal && $ship) {
                $add('ship');
            }
            $add('cetak_penuh');
        }

        return $out;
    }

    private static function shipLabel(?object $s): ?array
    {
        if (! $s) {
            return null;
        }
        if ((int) $s->cfo_result === 2 || (int) $s->ceo_result === 2) {
            return ['teks' => 'Shipping Instruction rejected', 'warna' => 'danger'];
        }

        return ['teks' => match ((int) $s->status) {
            3 => 'Shipping Instruction',
            0 => 'Shipping request by Purchasing',
            default => 'Shipping Instruction on progress',
        }, 'warna' => 'primary'];
    }

    public static function ship(int $idPo): ?object
    {
        if (! Schema::hasTable('new_pro_inventory_vendor_po_ship_req')) {
            return null;
        }

        return DB::table('new_pro_inventory_vendor_po_ship_req')->where('id_vendor_po', $idPo)->where('is_cancel', 0)->orderByDesc('id_master')->first();
    }

    public function index(Request $r): JsonResponse
    {
        $real = DB::table('new_pro_inventory_vendor_po_receive')
            ->selectRaw('id_po_supplier, sum(volume_terima) as vol_terima, max(volume_bol) as vol_bl')->groupBy('id_po_supplier');
        $q = $this->base()->leftJoinSub($real, 'rl', 'a.id_master', '=', 'rl.id_po_supplier')->where('a.harga_tebus', '>', 0);

        if (($v = trim((string) $r->query('q', ''))) !== '') {
            $q->whereRaw('upper(a.nomor_po) like ?', ['%'.strtoupper($v).'%']);
        }
        if ($r->filled('terminal')) {
            $q->where('a.id_terminal', (int) $r->query('terminal'));
        }
        if ($r->filled('vendor')) {
            $q->where('a.id_vendor', (int) $r->query('vendor'));
        }
        if ($r->filled('dari') && $r->filled('sampai')) {
            $q->whereBetween('a.tanggal_inven', [$r->query('dari'), $r->query('sampai')]);
        }

        $per = min(max((int) $r->query('per_page', 25), 1), (int) config('orca.per_page_max', 100));
        $page = $q->orderByDesc('a.tanggal_inven')->orderByDesc('a.id_master')->select([
            'a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal',
            'rl.vol_terima', 'rl.vol_bl', 'rl.id_po_supplier as ada_ri',
        ])->paginate($per);

        $sementara = Schema::hasColumn(self::PO, 'jenis_harga');
        $data = collect($page->items())->map(function ($p) use ($sementara) {
            $ship = self::ship((int) $p->id_master);
            $vt = (float) ($p->vol_terima ?? 0);
            [$status, $statusTgl] = array_pad(explode('|', self::status($p), 2), 2, null);

            return [
                'id' => (int) $p->id_master,
                'nomor_po' => $p->nomor_po,
                'tanggal' => $p->tanggal_inven,
                'vendor' => $p->nama_vendor,
                'terminal' => self::terminal($p),
                'produk' => $p->jenis_produk.' - '.$p->merk_dagang,
                'vol_po' => (float) $p->volume_po,
                'vol_bl' => (float) ($p->vol_bl ?? 0),
                'vol_ri' => $vt,
                'vol_close' => (float) $p->volume_close,
                'is_close' => (int) $p->is_close === 1,
                'is_cancel' => (int) $p->is_cancel === 1,
                'keterangan_cancel' => $p->keterangan_cancel,
                'harga_po' => (float) $p->harga_po,
                'harga_tebus' => (float) $p->harga_tebus,
                'harga_sementara' => $sementara && (int) ($p->jenis_harga ?? 0) === 2,
                'status' => $status,
                'status_tgl' => $statusTgl,
                'pengajuan_ulang' => (int) $p->is_resubmission === 1 ? (int) $p->resubmission_count : null,
                'shipping' => self::shipLabel($ship),
                'aksi' => self::aksi($p, $ship, $vt),
                'bisa_hapus' => $p->ada_ri === null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $p = $this->base()->where('a.id_master', $id)->select([
            'a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal', 'e.tanki_terminal', 'e.lokasi_terminal',
        ])->first();
        if (! $p) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }

        $ri = DB::table('new_pro_inventory_vendor_po_receive')->where('id_po_supplier', $id)->orderBy('id_po_receive')
            ->get(['id_po_receive', 'no_terima', 'tgl_terima', 'volume_bol', 'volume_terima', 'harga_tebus', 'nama_pic'])
            ->map(fn ($x) => (array) $x)->values();
        $volTerima = (float) $ri->sum('volume_terima');
        $ship = self::ship($id);

        $riwayat = DB::table('new_pro_inventory_vendor_po_history')->where('id_po_supplier', $id)->orderByDesc('id_master')->get()
            ->map(fn ($h) => [
                'tanggal' => $h->created_time, 'volume_po' => (float) $h->volume_po, 'harga_tebus' => (float) $h->harga_tebus,
                'total_order' => (float) $h->total_order, 'terms' => $h->terms, 'terms_day' => $h->terms_day,
                'keterangan' => $h->keterangan, 'keterangan_pengajuan_ulang' => $h->keterangan_resubmission ?? null,
            ])->values();

        $gl = DB::table('new_pro_inventory_gain_loss')->where('id_po_supplier', $id)->first(['volume', 'jenis', 'ket', 'disposisi_gain_loss']);
        [$status, $statusTgl] = array_pad(explode('|', self::status($p), 2), 2, null);
        $has = fn (string $c) => Schema::hasColumn(self::PO, $c);

        return response()->json([
            'id' => (int) $p->id_master, 'nomor_po' => $p->nomor_po, 'tanggal' => $p->tanggal_inven,
            'vendor' => $p->nama_vendor, 'terminal' => self::terminal($p), 'produk' => $p->jenis_produk.' - '.$p->merk_dagang,
            'jenis_kirim' => (int) $p->jenis_kirim, 'terms' => $p->terms, 'terms_day' => $p->terms_day, 'kd_tax' => $p->kd_tax,
            'volume_po' => (float) $p->volume_po, 'harga_po' => (float) $p->harga_po, 'harga_tebus' => (float) $p->harga_tebus,
            'jenis_harga' => $has('jenis_harga') ? (int) ($p->jenis_harga ?? 0) : null, 'is_price_changed' => $has('is_price_changed') ? (int) ($p->is_price_changed ?? 0) : null,
            'kategori_oa' => (int) $p->kategori_oa, 'is_biaya' => $p->is_biaya, 'kategori_plat' => $p->kategori_plat, 'ongkos_angkut' => (float) $p->ongkos_angkut,
            'iuran_migas' => (int) $p->iuran_migas === 1, 'nominal_migas' => (float) $p->nominal_migas,
            'subtotal' => (float) $p->subtotal, 'ppn_11' => (float) $p->ppn_11, 'dpp_11_12' => (float) $p->dpp_11_12, 'ppn_12' => (float) $p->ppn_12,
            'pph_22' => (float) $p->pph_22, 'pbbkb_persen' => (float) $p->nilai_pbbkb, 'pbbkb' => (float) $p->pbbkb, 'total_order' => (float) $p->total_order,
            'keterangan' => $p->keterangan, 'internal_notes' => $has('internal_notes') ? $p->internal_notes : null,
            'status' => $status, 'status_tgl' => $statusTgl, 'disposisi_po' => (int) $p->disposisi_po,
            'pengajuan_ulang' => (int) $p->is_resubmission === 1 ? (int) $p->resubmission_count : null,
            'cfo' => ['hasil' => (int) $p->cfo_result, 'pic' => $p->cfo_pic, 'tanggal' => $p->cfo_tanggal, 'catatan' => $p->cfo_summary, 'dikembalikan' => $p->revert_cfo_summary],
            'ceo' => ['hasil' => (int) $p->ceo_result, 'pic' => $p->ceo_pic, 'tanggal' => $p->ceo_tanggal, 'catatan' => $p->ceo_summary, 'dikembalikan' => $p->revert_ceo_summary],
            'is_close' => (int) $p->is_close === 1, 'tanggal_close' => $p->tanggal_close, 'volume_close' => (float) $p->volume_close,
            'is_cancel' => (int) $p->is_cancel === 1, 'keterangan_cancel' => $p->keterangan_cancel,
            'ri' => $ri, 'vol_ri' => $volTerima, 'vol_bl' => (float) $ri->sum('volume_bol'),
            'gain_loss' => $gl ? ['volume' => (float) $gl->volume, 'jenis' => $gl->jenis, 'ket' => $gl->ket] : null,
            'shipping' => self::shipLabel($ship),
            'riwayat' => $riwayat,
            'aksi' => self::aksi($p, $ship, $volTerima),
        ]);
    }

    /** "1.000" tanpa desimal bila bilangan bulat, selain itu 2 desimal (fmod di template cetak web). */
    private static function pas(float $n): string
    {
        return fmod($n, 1) == 0 ? number_format($n, 0, '.', ',') : number_format($n, 2, '.', ',');
    }

    /** Pembulatan iuran migas pada template: desimal <= 0,5 ke bawah, selain itu ke atas. */
    private static function bulat(float $n): float
    {
        if ($n < 0) {
            return ceil($n - 0.5);
        }

        return ($n - floor($n)) <= 0.5 ? floor($n) : ceil($n);
    }

    /** tgl_indo() web: "09 Juni 2026" (tanggal dua digit, bulan Indonesia panjang). */
    public static function tglIndo(?string $t): string
    {
        if (! $t || str_starts_with($t, '0000-00-00')) {
            return '';
        }
        $b = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        return substr($t, 8, 2).' '.$b[(int) substr($t, 5, 2)].' '.substr($t, 0, 4);
    }

    /**
     * Data cetakan PO. mode=standar: "Tanpa Gain & Loss" (po-supplier-vendor-syop.php, nilai dihitung ulang dari volume PO).
     * mode=gainloss: "Dengan Gain & Loss" (po-supplier-vendor-syop1.php, satu halaman per baris penerimaan).
     */
    public function cetak(Request $r, int $id): JsonResponse
    {
        $mode = $r->query('mode') === 'gainloss' ? 'gainloss' : 'standar';
        $p = $this->base()->where('a.id_master', $id)->select([
            'a.*', 'c.jenis_produk', 'c.merk_dagang', 'd.nama_vendor', 'e.nama_terminal',
        ])->first();
        if (! $p) {
            return response()->json(['message' => 'PO Supplier tidak ditemukan.'], 404);
        }
        $user = $r->user();
        $dicetak = $user->fullname.' '.date('d/m/Y H:i:s').' WIB';
        $kepala = [
            'nomor_po' => $p->nomor_po, 'tanggal' => self::tglIndo($p->tanggal_inven), 'vendor' => $p->nama_vendor,
            'terms' => trim($p->terms.' '.$p->terms_day), 'dicetak' => $dicetak, 'penandatangan' => 'Vica Krisdianatha',
        ];

        if ($mode === 'gainloss') {
            $baris = DB::table(self::PO.' as a')
                ->leftJoin('new_pro_inventory_vendor_po_receive as r', 'a.id_master', '=', 'r.id_po_supplier')
                ->leftJoin('new_pro_inventory_gain_loss as f', 'a.id_master', '=', 'f.id_po_supplier')
                ->where('a.id_master', $id)->get(['r.volume_bol', 'f.volume', 'f.ket']);
            $harga = (float) $p->harga_tebus;
            $halaman = $baris->map(function ($b) use ($p, $harga) {
                $bol = (float) $b->volume_bol;
                $vol = (float) $b->volume;
                $total = $harga * $bol;

                return [
                    'baris' => [
                        ['item' => $p->jenis_produk, 'deskripsi' => $p->merk_dagang, 'qty' => number_format($bol), 'harga' => 'IDR '.number_format($harga, 2, '.', ','),
                            'diskon' => 'IDR 0', 'pajak' => $p->kd_tax, 'jumlah' => 'IDR '.number_format($total, 2, '.', ',')],
                        ['item' => $p->jenis_produk, 'deskripsi' => $p->merk_dagang, 'qty' => number_format($vol), 'harga' => 'IDR '.number_format($harga, 2, '.', ','),
                            'diskon' => 'IDR 0', 'pajak' => $p->kd_tax, 'jumlah' => $vol > 0 ? 'IDR '.number_format($vol * $harga, 2, '.', ',') : '0'],
                    ],
                    'catatan' => ['PO : '.$p->keterangan, 'Gain/Loss : '.$b->ket],
                    'sub_total' => number_format((float) $p->subtotal, 2, '.', ','),
                    'dpp' => number_format(((float) $p->subtotal / 12) * 11, 2, '.', ','),
                    'ppn' => 'IDR '.number_format((float) $p->ppn_11, 2, '.', ','),
                    'pph22' => 'IDR '.number_format((float) $p->pph_22, 2, '.', ','),
                    'pbbkb' => 'IDR '.number_format((float) $p->pbbkb, 2, '.', ','),
                    'total_order' => 'IDR '.number_format((float) $p->total_order, 2, '.', ','),
                    'terbilang' => Terbilang::inggris((float) $p->total_order),
                ];
            })->values();

            return response()->json(['mode' => $mode, 'kepala' => $kepala + ['barcode' => '00'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)], 'halaman' => $halaman]);
        }

        $hargaDasar = (float) $p->harga_po > 0 ? (float) $p->harga_po : (float) $p->harga_tebus;
        $total = $hargaDasar * (float) $p->volume_po;
        $ppn = 11 * $total / 100;
        $pph = $total * 3 / 1000;
        $pbbkb = $total * ((float) $p->nilai_pbbkb / 100);
        $subtotal = $total;
        $totalOrder = $subtotal + $ppn + $pph + $pbbkb;
        $oa = (int) $p->kategori_oa === 2;
        $totalOa = 0.0;
        if ($oa) {
            $totalOa = (float) $p->ongkos_angkut * (float) $p->volume_po;
            $totalOrder = $subtotal + $ppn + $pph + $pbbkb + $totalOa;
            if ($p->kategori_plat === 'Hitam') {
                $subtotal += $totalOa;
            }
        }
        $migas = (int) $p->iuran_migas === 1;
        $totalIuran = $migas ? (float) $p->nominal_migas : 0.0;
        $vol = number_format((float) $p->volume_po);
        $baris = [['item' => $p->jenis_produk, 'deskripsi' => $p->merk_dagang, 'qty' => $vol, 'harga' => 'IDR '.number_format($hargaDasar, 0, '.', ','),
            'diskon' => 'IDR 0', 'pajak' => $p->kd_tax, 'jumlah' => 'IDR '.self::pas($total)]];
        if ($oa) {
            $baris[] = ['item' => 'Ongkos Angkut', 'deskripsi' => $p->merk_dagang, 'qty' => $vol, 'harga' => 'IDR '.self::pas((float) $p->ongkos_angkut),
                'diskon' => 'IDR 0', 'pajak' => $p->kd_tax, 'jumlah' => 'IDR '.self::pas($totalOa)];
        }
        if ($migas) {
            $baris[] = ['item' => 'Iuran Migas', 'deskripsi' => $p->merk_dagang, 'qty' => $vol, 'harga' => 'IDR '.self::bulat($hargaDasar * 0.25 / 100),
                'diskon' => 'IDR 0', 'pajak' => $p->kd_tax, 'jumlah' => 'IDR '.self::pas($totalIuran)];
        }
        $ec = $p->kd_tax === 'EC';
        $dpp = ($subtotal / 12) * 11;

        return response()->json(['mode' => $mode, 'kepala' => $kepala + ['barcode' => ParamCrypt::tersedia() ? config('orca.po_barcode_url').ParamCrypt::encrypt((string) $id) : null], 'halaman' => [[
            'baris' => $baris,
            'catatan' => [(string) $p->keterangan],
            'sub_total' => number_format($subtotal, 2, '.', ','),
            'dpp' => number_format($dpp, 2, '.', ','),
            'ppn' => 'IDR '.number_format($dpp * 12 / 100, 2, '.', ','),
            'pph22' => 'IDR '.number_format($ec ? $pph : (float) $p->pph_22, 2, '.', ','),
            'pbbkb' => 'IDR '.number_format($pbbkb, 2, '.', ','),
            'total_order' => 'IDR '.number_format($ec ? $totalOrder + $totalIuran : (float) $p->total_order, 2, '.', ','),
            'terbilang' => Terbilang::inggris((float) $p->total_order),
        ]]]);
    }
}
