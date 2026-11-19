<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi LCR (Location & Customer Review, pro_customer_lcr) untuk Branch Manager (peran 7), per cabangnya.
 * Sumber: verifikasi-lcr.php + datatable (daftar), verifikasi-lcr-detail.php, __get_data_lcr.php (data), __get_data_lcr_sm.php (form BM).
 * Keputusan BM (evaluation-lcr.php): sm_summary/sm_result/sm_tanggal/sm_pic + flag_approval = hasil (1 setuju, 2 tolak) + tgl_approval; tanpa email.
 * BM melihat LCR cabangnya yang sudah lewat Logistik (flag_disposisi > 1). Gambar ada di files/uploaded_user/files/<nama>.
 */
class LcrController extends Controller
{
    private const GAMBAR = [
        'jalan' => ['1. Kondisi Jalan Menuju Lokasi', 'kondisi_jalan'], 'kantor' => ['2. Pintu Gerbang & Kantor Perusahaan', 'kantor_perusahaan'],
        'storage' => ['3. Fasilitas Penyimpanan', 'fasilitas_storage'], 'inlet' => ['4. Inlet Pipa', 'inlet_pipa'], 'ukur' => ['5. Alat Ukur', 'alat_ukur_gambar'],
        'media' => ['6. Media Datar', 'media_datar'], 'ket' => ['7. Keterangan Penunjang Lain', 'keterangan_lain'],
    ];

    private function base(AclUser $u)
    {
        return DB::table('pro_customer_lcr as a')
            ->join('pro_master_provinsi as b', 'a.prov_survey', '=', 'b.id_prov')->join('pro_master_kabupaten as c', 'a.kab_survey', '=', 'c.id_kab')
            ->join('pro_customer as d', 'a.id_customer', '=', 'd.id_customer')->join('acl_user as e', 'd.id_marketing', '=', 'e.id_user')
            ->join('pro_master_cabang as f', 'a.id_wilayah', '=', 'f.id_master')
            ->where('d.id_wilayah', $u->id_wilayah)->where('a.flag_disposisi', '>', 1);
    }

    private function waiting($q)
    {
        return $q->where('a.flag_disposisi', 2)->where('a.sm_result', 0)->where('a.flag_approval', 0);
    }

    public function waitingCount(AclUser $u): int
    {
        return (int) $this->waiting($this->base($u))->count();
    }

    /** @return array<int,string> id_lcr => LCR0000 customer */
    public function waitingItems(AclUser $u): array
    {
        return $this->waiting($this->base($u))->orderByDesc('a.id_lcr')->limit(500)->get(['a.id_lcr as id', 'd.nama_customer as nama'])
            ->mapWithKeys(fn ($x) => [(int) $x->id => self::kode((int) $x->id).' - '.$x->nama])->all();
    }

    private static function kode(int $id): string
    {
        return 'LCR'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private static function tgl($v): ?string
    {
        return $v && ! str_starts_with((string) $v, '0000') ? date('d/m/Y H:i:s', strtotime($v)) : null;
    }

    private static function status(object $x): string
    {
        if (! (int) $x->flag_disposisi) {
            return 'Terdaftar';
        }
        if ((int) $x->flag_approval === 1) {
            return 'Disetujui BM ('.self::tgl($x->tgl_approval).')';
        }
        if ((int) $x->flag_approval === 2) {
            return 'Ditolak BM ('.self::tgl($x->tgl_approval).')';
        }
        if ((int) $x->flag_disposisi === -1) {
            return 'Ditolak Logistik';
        }

        return [1 => 'Verifikasi Logistik', 2 => 'Verifikasi BM'][(int) $x->flag_disposisi] ?? '-';
    }

    private static function json($v, $default = []): array
    {
        $d = json_decode((string) $v, true);

        return is_array($d) ? $d : $default;
    }

    private static function kab(?string $v): string
    {
        return str_replace(['KABUPATEN ', 'KOTA '], '', (string) $v);
    }

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = $this->base($u);
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->where(fn ($w) => $w->whereRaw('upper(d.nama_customer) like ?', ['%'.strtoupper($term).'%'])->orWhere('d.kode_pelanggan', $term));
        }
        if ($r->query('filter') === 'menunggu') {
            $this->waiting($q);
        }
        $page = $q->orderBy('a.flag_approval')->orderBy('a.sm_result')->orderByDesc('a.id_lcr')
            ->paginate(min(50, max(1, (int) $r->query('per_page', 20))), ['a.*', 'b.nama_prov', 'c.nama_kab', 'd.nama_customer', 'd.kode_pelanggan', 'e.fullname', 'f.nama_cabang']);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_lcr, 'kode' => self::kode((int) $x->id_lcr), 'tanggal' => date('d/m/Y', strtotime($x->created_time)),
                'kode_pelanggan' => $x->kode_pelanggan, 'customer' => $x->nama_customer, 'marketing' => $x->fullname,
                'surveyor' => array_values(array_filter(self::json($x->nama_surveyor))), 'tgl_survey' => $x->tgl_survey ? date('d/m/Y', strtotime($x->tgl_survey)) : null,
                'lokasi' => trim($x->alamat_survey.' '.self::kab($x->nama_kab).' '.$x->nama_prov), 'jarak_depot' => $x->jarak_depot ?: null, 'max_truk' => $x->max_truk ?: null,
                'status' => self::status($x), 'menunggu' => (int) $x->flag_disposisi === 2 && (int) $x->sm_result === 0 && (int) $x->flag_approval === 0,
                'ditolak' => (int) $x->flag_approval === 2 || (int) $x->flag_disposisi === -1,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(Request $r, int $id): ?object
    {
        return $this->base($r->user())->leftJoin('pro_master_wilayah_angkut as g', fn ($j) => $j->on('a.id_wil_oa', '=', 'g.id_master')->on('a.prov_survey', '=', 'g.id_prov')->on('a.kab_survey', '=', 'g.id_kab'))
            ->where('a.id_lcr', $id)->first(['a.*', 'b.nama_prov', 'c.nama_kab', 'd.nama_customer', 'd.kode_pelanggan', 'e.fullname', 'g.wilayah_angkut']);
    }

    private static function tahap($ringkas, $hasil, $pic, $tgl): array
    {
        return ['ringkasan' => $ringkas, 'hasil' => $hasil ? [1 => 'Ya', 2 => 'Tidak'][(int) $hasil] ?? null : null, 'oleh' => $pic ?: null, 'tanggal' => self::tgl($tgl)];
    }

    public function show(Request $r, int $id): JsonResponse
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Data LCR tidak ditemukan.'], 404);
        }
        $tangki = self::json($x->tangki);
        $kapal = self::json($x->kapal);
        $gambar = [];
        foreach (self::GAMBAR as $kunci => [$label, $kol]) {
            $items = collect(self::json($x->{$kol}))->filter(fn ($f) => ! empty($f['filename']))->values()
                ->map(fn ($f, $i) => ['idx' => $i, 'deskripsi' => $f['deskripsi'] ?? ''])->all();
            if ($items) {
                $gambar[] = ['kunci' => $kunci, 'label' => $label, 'items' => $items];
            }
        }
        $pic = collect(self::json($x->picustomer))->filter(fn ($p) => is_array($p) && ! empty($p['nama']))->values()->all();
        $jam = self::json($x->jam_operasional);

        return response()->json(['data' => [
            'id' => $id, 'kode' => self::kode($id), 'status' => self::status($x),
            'bisa_putus' => (int) $x->flag_disposisi === 2 && (int) $x->sm_result === 0 && (int) $x->flag_approval === 0,
            'logistik' => self::tahap($x->logistik_summary, $x->logistik_result, $x->logistik_pic, $x->logistik_tanggal),
            'bm' => self::tahap($x->sm_summary, $x->sm_result, $x->sm_pic, $x->sm_tanggal),
            'umum' => [
                'perusahaan' => $x->nama_customer, 'kode_pelanggan' => $x->kode_pelanggan, 'marketing' => $x->fullname,
                'alamat' => trim($x->alamat_survey.' '.self::kab($x->nama_kab).' '.$x->nama_prov), 'telp' => $x->telp_survey, 'wilayah_oa' => $x->wilayah_angkut,
                'fax' => $x->fax_survey, 'website' => $x->website, 'tgl_survey' => $x->tgl_survey ? date('d/m/Y', strtotime($x->tgl_survey)) : null,
                'jenis_usaha' => $x->jenis_usaha, 'toleransi' => $x->toleransi,
                'surveyor' => array_values(array_filter(self::json($x->nama_surveyor))), 'penanggung_jawab' => $pic,
                'kompetitor' => array_values(array_filter(self::json($x->kompetitor))), 'hasil_produksi' => array_values(array_filter(self::json($x->hasilsurv))),
                'produksi_bulanan' => collect(self::json($x->produkvol))->pluck('volbul')->filter()->values()->all(),
                'jam' => ['senin_jumat' => $jam[0] ?? null, 'sabtu' => $jam[1] ?? null, 'minggu' => $jam[2] ?? null],
            ],
            'lokasi' => [
                'rute' => $x->rute_lokasi, 'catatan' => $x->note_lokasi, 'link_maps' => $x->link_google_maps, 'latitude' => $x->latitude_lokasi, 'longitude' => $x->longitude_lokasi,
                'jarak_depot' => $x->jarak_depot ? $x->jarak_depot.' KM' : null, 'max_truk' => $x->max_truk, 'biaya_koordinasi' => $x->lsm_portal,
                'min_vol_kirim' => $x->min_vol_kirim ? $x->min_vol_kirim.' KL' : null, 'penjelasan_bongkar' => $x->penjelasan_bongkar,
                'ada_peta' => (bool) $x->layout_lokasi, 'ada_layout' => (bool) $x->layout_bongkar,
            ],
            'bongkar' => [
                'tangki' => $tangki, 'pendukung' => self::json($x->pendukung), 'quantity_tangki' => self::json($x->quantity_tangki), 'quality_tangki' => self::json($x->quality_tangki), 'catatan_tangki' => $x->catatan_tangki,
                'kapal' => $kapal, 'jetty' => self::json($x->jetty), 'quantity_kapal' => self::json($x->quantity_kapal), 'quality_kapal' => self::json($x->quality_kapal), 'catatan_kapal' => $x->catatan_kapal,
            ],
            'gambar' => $gambar,
        ]]);
    }

    /** jenis: peta (layout_lokasi), layout (layout_bongkar), atau salah satu kunci gambar + idx. */
    public function gambar(Request $r, int $id, string $jenis, int $idx = 0)
    {
        $x = $this->find($r, $id);
        if (! $x) {
            return response()->json(['message' => 'Data LCR tidak ditemukan.'], 404);
        }
        $nama = null;
        if ($jenis === 'peta') {
            $nama = $x->layout_lokasi;
        } elseif ($jenis === 'layout') {
            $nama = $x->layout_bongkar;
        } elseif (isset(self::GAMBAR[$jenis])) {
            $f = collect(self::json($x->{self::GAMBAR[$jenis][1]}))->filter(fn ($f) => ! empty($f['filename']))->values()->get($idx);
            $nama = $f['filename'] ?? null;
        }

        return LegacyFile::response($nama ? 'files/'.basename($nama) : null, $nama ?: 'gambar');
    }

    public function decide(Request $r, int $id): \Illuminate\Http\JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data LCR tidak ditemukan.'], 404);
        }
        $d = $r->validate(['result' => 'required|in:1,2', 'summary' => 'required|string|max:5000']);
        $u = $r->user();
        $res = (int) $d['result'];
        $summary = str_replace(["\r\n", "\r", "\n"], '<br />', htmlspecialchars($d['summary'], ENT_QUOTES));

        return DB::transaction(function () use ($id, $u, $res, $summary) {
            $x = DB::table('pro_customer_lcr')->where('id_lcr', $id)->lockForUpdate()->first();
            if ((int) $x->flag_disposisi !== 2 || (int) $x->sm_result !== 0 || (int) $x->flag_approval !== 0) {
                return response()->json(['message' => 'LCR ini sudah diputuskan atau belum waktunya Anda memutuskan.', 'code' => 'STATE'], 409);
            }
            DB::table('pro_customer_lcr')->where('id_lcr', $id)->update([
                'sm_summary' => $summary, 'sm_result' => $res, 'sm_tanggal' => now(), 'sm_pic' => $u->fullname, 'flag_approval' => $res, 'tgl_approval' => now(),
            ]);

            return response()->json(['ok' => true, 'status' => $res === 1 ? 'disetujui' : 'ditolak']);
        });
    }
}
