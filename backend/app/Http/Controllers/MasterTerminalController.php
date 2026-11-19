<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master Terminal (pro_master_terminal + pro_master_terminal_detail) untuk Purchasing/Procurement (peran 5).
 * Sumber: master-terminal.php, master-terminal (1).php (daftar), add-master-terminal.php, detil-master-terminal.php, master-terminal (2).php (simpan).
 * Daftar, detail, tambah, ubah. Tidak ada hapus dan unggah lampiran dokumen (file web tidak dipindahkan); dokumen perizinan memuat nama dan masa berlaku.
 * Teks disimpan ter-escape HTML (ENT_QUOTES) seperti web; kolom attention disimpan JSON di att_terminal.
 */
class MasterTerminalController extends Controller
{
    private const KATEGORI = [1 => 'Depo', 2 => 'Dispenser', 3 => 'Truck Gantung'];

    private static function esc(?string $s): string
    {
        return htmlspecialchars(trim((string) $s), ENT_QUOTES);
    }

    private static function plain(?string $s): string
    {
        return html_entity_decode(str_replace(['<br />', '<br>'], "\n", (string) $s), ENT_QUOTES);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'cabang' => DB::table('pro_master_cabang')->where('is_active', 1)->where('id_master', '<>', 1)->orderBy('nama_cabang')->get(['id_master as id', 'nama_cabang as nama']),
            'kategori' => collect(self::KATEGORI)->map(fn ($n, $k) => ['id' => $k, 'nama' => $n])->values(),
        ]);
    }

    public function index(Request $r): JsonResponse
    {
        $q = DB::table('pro_master_terminal as a')->leftJoin('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master');
        $st = (string) $r->query('status', '1');
        if ($st === '1' || $st === '0') {
            $q->where('a.is_active', (int) $st);
        }
        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $q->whereRaw('upper(a.nama_terminal) like ?', ['%'.strtoupper(htmlspecialchars($term, ENT_QUOTES)).'%']);
        }
        if ($r->filled('cabang')) {
            $q->where('a.id_cabang', (int) $r->query('cabang'));
        }
        $per = min(max((int) $r->query('per_page', 20), 1), (int) config('orca.per_page_max'));
        $page = $q->orderBy('a.is_active')->orderBy('a.id_cabang')->orderBy('a.nama_terminal')
            ->select('a.id_master', 'a.initial', 'a.nama_terminal', 'a.tanki_terminal', 'a.lokasi_terminal', 'a.kategori_terminal', 'a.batas_atas', 'a.batas_bawah', 'a.is_active', 'b.nama_cabang')
            ->paginate($per);

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_master, 'initial' => $x->initial, 'nama' => self::plain($x->nama_terminal), 'tanki' => self::plain($x->tanki_terminal),
                'cabang' => $x->nama_cabang, 'lokasi' => self::plain($x->lokasi_terminal), 'kategori' => self::KATEGORI[(int) $x->kategori_terminal] ?? '-',
                'batas_atas' => (int) $x->batas_atas, 'batas_bawah' => (int) $x->batas_bawah, 'aktif' => (int) $x->is_active === 1,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $x = DB::table('pro_master_terminal as a')->leftJoin('pro_master_cabang as b', 'a.id_cabang', '=', 'b.id_master')->where('a.id_master', $id)
            ->first(['a.*', 'b.nama_cabang']);
        if (! $x) {
            return response()->json(['message' => 'Terminal tidak ditemukan.'], 404);
        }
        $att = collect(json_decode((string) $x->att_terminal, true) ?: [])->map(fn ($a) => [
            'nama' => self::plain($a['nama'] ?? ''), 'posisi' => self::plain($a['posisi'] ?? ''), 'hp' => self::plain($a['hp'] ?? ''), 'email' => self::plain($a['email'] ?? ''),
        ])->values();
        $docs = DB::table('pro_master_terminal_detail')->where('id_terminal', $id)->orderBy('id_td')
            ->get(['id_td', 'dokumen', 'masa_berlaku', 'lampiran_ori'])
            ->map(fn ($d) => ['id' => (int) $d->id_td, 'dokumen' => self::plain($d->dokumen), 'masa_berlaku' => $d->masa_berlaku, 'lampiran' => $d->lampiran_ori])->values();

        return response()->json([
            'id' => (int) $x->id_master, 'nama' => self::plain($x->nama_terminal), 'tanki' => self::plain($x->tanki_terminal), 'initial' => $x->initial,
            'id_cabang' => $x->id_cabang ? (int) $x->id_cabang : null, 'cabang' => $x->nama_cabang, 'kategori' => (int) $x->kategori_terminal,
            'kategori_nama' => self::KATEGORI[(int) $x->kategori_terminal] ?? '-', 'batas_atas' => (int) $x->batas_atas, 'batas_bawah' => (int) $x->batas_bawah,
            'lokasi' => self::plain($x->lokasi_terminal), 'telp' => self::plain($x->telp_terminal), 'alamat' => self::plain($x->alamat_terminal), 'fax' => self::plain($x->fax_terminal),
            'pic' => self::plain($x->cc_terminal), 'latitude' => $x->latitude, 'longitude' => $x->longitude, 'catatan' => self::plain($x->catatan_terminal),
            'aktif' => (int) $x->is_active === 1, 'attention' => $att, 'dokumen' => $docs,
            'dibuat_oleh' => $x->created_by, 'dibuat_pada' => $x->created_time, 'diubah_oleh' => $x->lastupdate_by, 'diubah_pada' => $x->lastupdate_time,
        ]);
    }

    private function input(Request $r): array
    {
        return $r->validate([
            'nama' => ['required', 'string', 'max:80'], 'tanki' => ['required', 'string', 'max:50'], 'initial' => ['required', 'string', 'max:20'],
            'id_cabang' => ['required', 'integer', 'exists:pro_master_cabang,id_master'], 'kategori' => ['required', 'integer', 'in:1,2,3'],
            'batas_atas' => ['nullable', 'integer', 'min:0'], 'batas_bawah' => ['nullable', 'integer', 'min:0'],
            'lokasi' => ['required', 'string', 'max:2000'], 'telp' => ['required', 'string', 'max:20'], 'alamat' => ['required', 'string', 'max:255'],
            'fax' => ['required', 'string', 'max:20'], 'pic' => ['required', 'string', 'max:20'], 'latitude' => ['required', 'string', 'max:50'], 'longitude' => ['required', 'string', 'max:50'],
            'catatan' => ['nullable', 'string', 'max:1000'], 'aktif' => ['required', 'boolean'],
            'attention' => ['nullable', 'array', 'max:50'], 'attention.*.nama' => ['nullable', 'string', 'max:100'], 'attention.*.posisi' => ['nullable', 'string', 'max:100'],
            'attention.*.hp' => ['nullable', 'string', 'max:50'], 'attention.*.email' => ['nullable', 'string', 'max:100'],
            'dokumen_baru' => ['nullable', 'array', 'max:50'], 'dokumen_baru.*.dokumen' => ['required', 'string', 'max:250'], 'dokumen_baru.*.masa_berlaku' => ['nullable', 'date'],
            'hapus_dokumen' => ['nullable', 'array'], 'hapus_dokumen.*' => ['integer'],
        ]);
    }

    private function cols(array $d): array
    {
        $att = [];
        foreach ($d['attention'] ?? [] as $a) {
            $nama = self::esc($a['nama'] ?? '');
            if ($nama !== '') { // baris tanpa nama dilewati, sama dengan web
                $att[] = ['nama' => $nama, 'posisi' => self::esc($a['posisi'] ?? ''), 'hp' => self::esc($a['hp'] ?? ''), 'email' => self::esc($a['email'] ?? '')];
            }
        }

        return [
            'nama_terminal' => self::esc($d['nama']), 'tanki_terminal' => self::esc($d['tanki']), 'initial' => self::esc($d['initial']), 'id_cabang' => (int) $d['id_cabang'],
            'kategori_terminal' => (string) $d['kategori'], 'batas_atas' => (int) ($d['batas_atas'] ?? 0), 'batas_bawah' => (int) ($d['batas_bawah'] ?? 0),
            'lokasi_terminal' => self::esc($d['lokasi']), 'telp_terminal' => self::esc($d['telp']), 'alamat_terminal' => self::esc($d['alamat']), 'fax_terminal' => self::esc($d['fax']),
            'cc_terminal' => self::esc($d['pic']), 'latitude' => self::esc($d['latitude']), 'longitude' => self::esc($d['longitude']),
            'catatan_terminal' => str_replace(["\r\n", "\r", "\n"], '<br />', self::esc($d['catatan'] ?? '')), 'att_terminal' => json_encode($att), 'is_active' => $d['aktif'] ? 1 : 0,
        ];
    }

    private function docs(int $id, array $d): void
    {
        foreach ($d['dokumen_baru'] ?? [] as $x) {
            DB::table('pro_master_terminal_detail')->insert([
                'id_terminal' => $id, 'dokumen' => self::esc($x['dokumen']), 'masa_berlaku' => ! empty($x['masa_berlaku']) ? $x['masa_berlaku'] : null,
            ]);
        }
        if (! empty($d['hapus_dokumen'])) {
            DB::table('pro_master_terminal_detail')->where('id_terminal', $id)->whereIn('id_td', $d['hapus_dokumen'])->delete();
        }
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->input($r);
        $id = DB::transaction(function () use ($r, $d) {
            $id = DB::table('pro_master_terminal')->insertGetId($this->cols($d) + ['id_area' => 0, 'created_time' => now(), 'created_ip' => (string) $r->ip(), 'created_by' => $r->user()->fullname]);
            $this->docs($id, $d);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        if (! DB::table('pro_master_terminal')->where('id_master', $id)->exists()) {
            return response()->json(['message' => 'Terminal tidak ditemukan.'], 404);
        }
        $d = $this->input($r);
        DB::transaction(function () use ($r, $id, $d) {
            DB::table('pro_master_terminal')->where('id_master', $id)->update($this->cols($d) + ['lastupdate_time' => now(), 'lastupdate_ip' => (string) $r->ip(), 'lastupdate_by' => $r->user()->fullname]);
            $this->docs($id, $d);
        });

        return response()->json(['ok' => true]);
    }
}
