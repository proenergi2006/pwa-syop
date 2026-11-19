<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Input Report RSSP khusus Procurement/Purchasing (peran 5), satu sel (terminal + tanggal) per aksi.
 * Sumber: forecast-supply.php + __get_forecast_supply_form.php (Forecast Supply), forecast-sales-plan.php + __get_forecast_sales_plan_form.php (Forecast Sales Planning).
 *
 * - supply: rencana kedatangan BBM di satu terminal pada satu tanggal (pro_forecast_supply). Hanya sel yang BELUM punya realisasi GR yang boleh diisi
 *   (di web sel dengan realisasi tidak bisa diklik; di sini juga ditolak di server).
 * - plan: alokasi forecast sales per terminal (pro_forecast_sales). Syarat: periode forecast cabang terminal itu (1 = tgl 1-14, 2 = tgl 15-akhir) sudah
 *   APPROVED CEO, dan total alokasi semua terminal di cabang yang sama pada tanggal itu tidak boleh melebihi total forecast cabang (pro_forecast.qty x 1000).
 * Volume dalam liter (angka penuh). Keterangan disimpan ter-escape HTML seperti web, dibaca kembali apa adanya.
 */
class RsspInputController extends Controller
{
    private const KECUALI = [92, 101];
    private const TABEL = ['supply' => 'pro_forecast_supply', 'plan' => 'pro_forecast_sales'];

    private function terminal(int $tid): ?object
    {
        return DB::table('pro_master_terminal as t')->leftJoin('pro_master_cabang as c', 'c.id_master', '=', 't.id_cabang')
            ->where('t.id_master', $tid)->where('t.is_active', 1)->whereIn('t.kategori_terminal', [1, 3])->whereNotIn('t.id_master', self::KECUALI)
            ->first(['t.id_master', 't.id_cabang', 't.nama_terminal', 't.tanki_terminal', 'c.nama_cabang']);
    }

    private static function nama(object $t): string
    {
        return trim($t->nama_terminal.' '.($t->tanki_terminal ?? ''));
    }

    /** Realisasi GR (supply actual) terminal itu pada tanggal itu, dengan rumus yang sama dengan matriks RSSP. */
    private function supplyActual(int $tid, string $tanggal): float
    {
        return (float) (DB::selectOne("SELECT COALESCE(SUM(CASE WHEN t.kategori_terminal = 3 THEN GREATEST(d.adj_inven, 0)
                ELSE d.in_inven + (CASE WHEN d.id_jenis = 4 AND d.adj_inven > 0 THEN d.adj_inven ELSE 0 END) END), 0) AS s
            FROM new_pro_inventory_depot d JOIN pro_master_terminal t ON t.id_master = d.id_terminal
            WHERE d.id_terminal = ? AND d.tanggal_inven = ?", [$tid, $tanggal])->s ?? 0);
    }

    private static function periode(string $tanggal): array
    {
        $ts = strtotime($tanggal);

        return [(int) date('j', $ts) <= 14 ? 1 : 2, (int) date('n', $ts), (int) date('Y', $ts)];
    }

    private function disetujui(int $cabang, string $tanggal): bool
    {
        [$p, $b, $y] = self::periode($tanggal);

        return DB::table('pro_forecast_approval')->where('id_wilayah', $cabang)->where('bulan', $b)->where('tahun', $y)->where('periode', $p)
            ->orderByDesc('id_approval')->value('status') === 'APPROVED';
    }

    /** Total forecast cabang di tanggal itu (liter) dan yang sudah dialokasikan ke terminal lain. */
    private function alokasi(int $cabang, int $tid, string $tanggal): array
    {
        $total = (float) DB::table('pro_forecast as f')->join('pro_customer as c', 'c.id_customer', '=', 'f.id_customer')->where('f.tanggal', $tanggal)->where('c.id_wilayah', $cabang)->sum('f.qty') * 1000;
        $lain = (float) DB::table('pro_forecast_sales as s')->join('pro_master_terminal as t', 't.id_master', '=', 's.id_terminal')->where('t.id_cabang', $cabang)
            ->where('s.tanggal', $tanggal)->where('s.id_terminal', '!=', $tid)->sum('s.qty');

        return [$total, $lain, $total - $lain];
    }

    private static function plain(?string $s): string
    {
        return $s === null ? '' : html_entity_decode($s, ENT_QUOTES);
    }

    /** Bentuk isian satu sel: nilai sekarang, dan (untuk plan) sisa alokasi serta status persetujuan CEO. */
    public function show(Request $r): JsonResponse
    {
        $v = $this->validasi($r->query('jenis'), $r->query('tid'), $r->query('tanggal'));
        if ($v instanceof JsonResponse) {
            return $v;
        }
        [$jenis, $t, $tanggal] = $v;
        $row = DB::table(self::TABEL[$jenis])->where('id_terminal', $t->id_master)->where('tanggal', $tanggal)->first(['qty', 'keterangan']);
        $out = [
            'jenis' => $jenis, 'tanggal' => $tanggal, 'terminal' => ['id' => (int) $t->id_master, 'nama' => self::nama($t), 'cabang_id' => (int) $t->id_cabang, 'cabang' => $t->nama_cabang],
            'ada' => (bool) $row, 'qty' => $row ? (int) $row->qty : null, 'keterangan' => $row ? self::plain($row->keterangan) : '',
        ];
        if ($jenis === 'supply') {
            $act = $this->supplyActual((int) $t->id_master, $tanggal);
            $out += ['actual' => $act, 'terkunci' => $act > 0];
        } else {
            [$p] = self::periode($tanggal);
            [$total, $lain, $sisa] = $this->alokasi((int) $t->id_cabang, (int) $t->id_master, $tanggal);
            $out += ['periode' => $p, 'disetujui' => $this->disetujui((int) $t->id_cabang, $tanggal), 'total_forecast' => $total, 'dialokasi_lain' => $lain, 'sisa' => $sisa];
        }

        return response()->json($out);
    }

    public function save(Request $r): JsonResponse
    {
        $data = $r->validate(['qty' => ['required', 'numeric', 'min:0', 'max:1000000000'], 'keterangan' => ['nullable', 'string', 'max:255']], ['qty.required' => 'Volume wajib diisi.', 'qty.numeric' => 'Volume tidak valid.', 'qty.min' => 'Volume tidak valid.']);
        $v = $this->validasi($r->input('jenis'), $r->input('tid'), $r->input('tanggal'));
        if ($v instanceof JsonResponse) {
            return $v;
        }
        [$jenis, $t, $tanggal] = $v;
        $qty = (int) round((float) $data['qty']); // kolom qty bertipe integer (liter)
        $tid = (int) $t->id_master;

        if ($jenis === 'supply') {
            if ($this->supplyActual($tid, $tanggal) > 0) {
                return response()->json(['message' => 'Sudah ada realisasi GR pada tanggal ini, forecast supply tidak bisa diisi.', 'code' => 'ACTUAL'], 409);
            }
        } else {
            [$p] = self::periode($tanggal);
            if (! $this->disetujui((int) $t->id_cabang, $tanggal)) {
                return response()->json(['message' => 'Forecast periode '.($p === 1 ? '1 (tgl 1-14)' : '2 (tgl 15-akhir)').' bulan ini belum di-approve CEO.', 'code' => 'NOT_APPROVED'], 409);
            }
            [$total, $lain, $sisa] = $this->alokasi((int) $t->id_cabang, $tid, $tanggal);
            if ($qty > $sisa + 0.0001) { // toleransi pembulatan desimal
                $f = fn ($n) => number_format($n, 1, ',', '.');

                return response()->json([
                    'message' => 'Melebihi sisa alokasi. Total Forecast: '.$f($total).' L, sudah dialokasikan ke terminal lain: '.$f($lain).' L, sisa maksimal: '.$f($sisa).' L.',
                    'code' => 'OVER_ALLOCATION', 'sisa' => $sisa,
                ], 422);
            }
        }

        $user = $r->user();
        $ket = htmlspecialchars((string) ($data['keterangan'] ?? ''), ENT_QUOTES);
        $now = now();
        $cur = DB::table(self::TABEL[$jenis])->where('id_terminal', $tid)->where('tanggal', $tanggal)->first(['id_master']);
        if ($cur) {
            DB::table(self::TABEL[$jenis])->where('id_master', $cur->id_master)->update(['qty' => $qty, 'keterangan' => $ket, 'lastupdate_time' => $now, 'lastupdate_ip' => $r->ip(), 'lastupdate_by' => $user->fullname]);
        } else {
            DB::table(self::TABEL[$jenis])->insert(['id_terminal' => $tid, 'tanggal' => $tanggal, 'qty' => $qty, 'keterangan' => $ket, 'created_time' => $now, 'created_ip' => $r->ip(), 'created_by' => $user->fullname]);
        }
        $this->lupakanCache((int) $t->id_cabang, $tanggal);

        return response()->json(['message' => 'Tersimpan.']);
    }

    public function destroy(Request $r): JsonResponse
    {
        $v = $this->validasi($r->input('jenis'), $r->input('tid'), $r->input('tanggal'));
        if ($v instanceof JsonResponse) {
            return $v;
        }
        [$jenis, $t, $tanggal] = $v;
        DB::table(self::TABEL[$jenis])->where('id_terminal', $t->id_master)->where('tanggal', $tanggal)->delete();
        $this->lupakanCache((int) $t->id_cabang, $tanggal);

        return response()->json(['message' => 'Terhapus.']);
    }

    /** @return array{0:string,1:object,2:string}|JsonResponse */
    private function validasi(mixed $jenis, mixed $tid, mixed $tanggal): array|JsonResponse
    {
        if (! isset(self::TABEL[(string) $jenis]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tanggal) || ! (int) $tid) {
            return response()->json(['message' => 'Data tidak lengkap.'], 422);
        }
        $t = $this->terminal((int) $tid);
        if (! $t) {
            return response()->json(['message' => 'Terminal tidak ditemukan.'], 404);
        }

        return [(string) $jenis, $t, (string) $tanggal];
    }

    /** Hasil Report RSSP di-cache beberapa menit per cabang/bulan; setelah input langsung dihapus supaya angka baru tampil. */
    private function lupakanCache(int $cabang, string $tanggal): void
    {
        [, $b, $y] = self::periode($tanggal);
        foreach ([(string) $cabang, 'nasional'] as $k) {
            Cache::forget("rssp:$k:$b:$y:".date('Ymd'));
        }
    }
}
