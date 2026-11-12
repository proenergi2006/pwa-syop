<?php

namespace App\Services;

use App\Models\AclUser;
use Illuminate\Database\Query\Builder;
use App\Services\Accurate\AccurateException;
use App\Services\Accurate\AccurateSoPriceSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alur approval penawaran, dipindahkan dari penawaran-approval.php (web lama).
 *
 * Tahap (pro_penawaran.flag_disposisi): 3 BM, 4 OM, 5 COO, 6 CEO.
 * Hasil keputusan: 1 = setuju, 2 = tolak. Tolak selalu final (flag_approval=2).
 *
 * Pengajuan perubahan harga (is_edit_harga = 1) didukung: harga baru diterapkan saat persetujuan final (credit limit, Sales Order Accurate bila
 * Accurate aktif, penawaran, harga PO customer, Other Cost), dengan riwayat P002 dan riwayat request seperti web.
 */
class PenawaranApprovalService
{
    private const APPROVE = 1;
    private const REJECT  = 2;

    // ------------------------------------------------------------------ query

    public function baseQuery(): Builder
    {
        return DB::table('pro_penawaran as a')
            ->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')
            ->join('acl_user as c', 'b.id_marketing', '=', 'c.id_user')
            ->join('pro_master_cabang as d', 'a.id_cabang', '=', 'd.id_master')
            ->join('pro_master_produk as e', 'a.produk_tawar', '=', 'e.id_master')
            ->join('pro_master_area as g', 'a.id_area', '=', 'g.id_master')
            ->select([
                'a.id_penawaran', 'a.id_customer', 'a.nomor_surat', 'a.tipe_penawaran',
                'a.volume_tawar', 'a.harga_dasar', 'a.harga_asli', 'a.masa_awal', 'a.masa_akhir',
                'a.flag_disposisi', 'a.flag_approval', 'a.is_edit_harga', 'a.tier', 'a.created_time',
                'b.nama_customer', 'c.fullname as marketing', 'd.nama_cabang',
                'e.jenis_produk', 'e.merk_dagang', 'g.nama_area',
            ]);
    }

    /** Penawaran yang sedang menunggu keputusan user ini. */
    public function pendingQuery(AclUser $user): Builder
    {
        $role  = (int) $user->id_role;
        $stage = array_search($role, config('orca.stage_role'), true);
        $col   = config("orca.role_column.$role");

        $q = $this->baseQuery()
            // kolom ini hanya untuk menghitung peringatan di kartu daftar (tidak dikirim ke klien)
            ->addSelect(['a.metode', 'a.oa_kirim', 'a.other_cost', 'a.refund_tawar', 'a.detail_rincian'])
            ->where('a.flag_approval', 0);

        if ($stage === false || ! $col) {
            return $q->whereRaw('1 = 0');
        }

        $q->where('a.flag_disposisi', $stage)
            ->where(fn ($w) => $w->whereNull("a.{$col}_result")->orWhere("a.{$col}_result", 0));

        if ($role === (int) config('orca.roles.bm')) {
            $q->where('a.id_cabang', $user->id_wilayah);
        }

        return $q;
    }

    /** Penawaran yang pernah diputuskan user ini (dari pro_approval_hist). */
    public function historyQuery(AclUser $user): Builder
    {
        return $this->baseQuery()
            ->join('pro_approval_hist as h', 'h.id_penawaran', '=', 'a.id_penawaran')
            ->where('h.id_user', (string) $user->id_user)
            ->addSelect(['h.result as keputusan', 'h.tgl_approval as tgl_keputusan', 'h.summary as catatan_keputusan'])
            ->orderByDesc('h.tgl_approval');
    }

    /** Ringkasan untuk beranda: yang menunggu, yang terlama, dan keputusan 7 hari terakhir. */
    public function ringkasan(AclUser $user): array
    {
        $pending = (clone $this->pendingQuery($user))->reorder();
        $oldest  = (clone $pending)->min('a.created_time');

        $decided = fn (string $result) => DB::table('pro_approval_hist')
            ->where('id_user', (string) $user->id_user)
            ->where('kd_approval', 'P001')
            ->where('result', $result)
            ->where('tgl_approval', '>=', now()->subDays(7))
            ->distinct()
            ->count('id_penawaran');

        return [
            'menunggu'        => (clone $pending)->count(),
            'terlama'         => $oldest,
            'disetujui_7hari' => $decided('1'),
            'ditolak_7hari'   => $decided('2'),
        ];
    }

    public function find(int $id): ?object
    {
        return DB::table('pro_penawaran')->where('id_penawaran', $id)->first();
    }

    // ----------------------------------------------------------------- detail

    public function detail(AclUser $user, int $id): array
    {
        $row = $this->detailQuery()->where('a.id_penawaran', $id)->first();

        if (! $row) {
            throw new ApprovalException('Penawaran tidak ditemukan.', 404, 'NOT_FOUND');
        }

        $full = $this->find($id);

        $otherCosts = DB::table('pro_other_cost_detail')
            ->where('id_penawaran', $id)
            ->orderBy('id_master')
            ->get(['keterangan', 'nominal']);

        $canAct   = $this->canAct($user, $full);
        $pc       = $this->priceChangeView($full);
        $warnings = $this->warnings($pc && $pc['_baru'] ? $this->withBaru($full, $pc['_baru']) : $full);

        return [
            'penawaran'        => $row,
            'customer'         => $this->customerBlock($row),
            'pricelist'        => $this->pricelist($user, $row),
            'rincian'          => $this->rincian($full),
            'price_check'      => $this->priceCheck($this->pricelist($user, $row), $this->rincian($full)),
            'formula'          => (int) $full->perhitungan === 1 ? [] : $this->decode($full->detail_formula),
            'rincian_note'     => ['pembulatan' => (int) $full->pembulatan === 1, 'gabung_oa' => (int) $full->gabung_oa === 1],
            'totals'           => $this->totals($full),
            'other_costs'      => $otherCosts,
            'tahap'            => $this->stageLabel($full),
            'status_approval'  => $this->statusApproval($full, $row->nama_cabang),
            'timeline'         => $this->timeline($full),
            'history_approval' => $this->historyApproval($full),
            'other_penawaran'  => $this->otherPenawaran($full),
            'warnings'         => $warnings,
            'can_act'          => $canAct,
            'unsupported'      => false,
            'perubahan_harga'  => $pc ? array_diff_key($pc, ['_baru' => 1]) : null,
            'decision_form'    => $canAct ? $this->decisionForm($user, $full, $warnings) : null,
        ];
    }

    /** Kueri detail: dasar + kolom penawaran lengkap + harga rekomendasi (sama seperti web lama). */
    private function detailQuery(): Builder
    {
        $f = fn (string $col) => DB::raw("COALESCE(NULLIF(f.{$col}, 0), i.{$col}) as {$col}");

        return $this->baseQuery()
            ->leftJoin('pro_master_harga_minyak as f', function ($j) {
                $j->on('a.masa_awal', '=', 'f.periode_awal')
                  ->on('a.masa_akhir', '=', 'f.periode_akhir')
                  ->on('a.id_area', '=', 'f.id_area')
                  ->on('a.pbbkb_tawar', '=', 'f.pajak')
                  ->on('a.produk_tawar', '=', 'f.produk')
                  ->where('f.is_approved', 1);
            })
            ->leftJoin('pro_master_harga_minyak as i', function ($j) {
                $j->on('a.masa_awal', '=', 'i.periode_awal')
                  ->on('a.masa_akhir', '=', 'i.periode_akhir')
                  ->on('a.id_area', '=', 'i.id_area')
                  ->on('a.produk_tawar', '=', 'i.produk')
                  ->where('i.pajak', 1)
                  ->where('i.is_approved', 1);
            })
            ->addSelect([
                'a.detail_rincian', 'a.catatan', 'a.metode', 'a.oa_kirim', 'a.other_cost',
                'a.refund_tawar', 'a.harga_tier', 'a.term_condition', 'a.jenis_payment', 'a.jenis_net',
                'a.jangka_waktu', 'a.lok_kirim', 'a.gelar', 'a.nama_up', 'a.jabatan_up', 'a.alamat_up', 'a.telp_up',
                'a.tol_susut', 'a.alat_ukur', 'a.pbbkb_tawar', 'a.method_order', 'a.perhitungan',
                'a.pembulatan', 'a.gabung_oa', 'a.is_edited', 'a.id_group',
                $f('harga_normal'), $f('harga_sm'), $f('harga_om'), $f('harga_coo'), $f('harga_ceo'),
            ]);
    }

    private function customerBlock(object $r): array
    {
        $kond = [0 => '', 1 => 'Setelah Invoice diterima', 2 => 'Setelah pengiriman', 3 => 'Setelah loading'];
        $top = match ($r->jenis_payment) {
            'CREDIT' => trim('CREDIT ' . (int) $r->jangka_waktu . ' hari ' . ($kond[(int) $r->jenis_net] ?? '')),
            'CBD'    => 'CBD (Cash Before Delivery)',
            'COD'    => 'COD (Cash On Delivery)',
            default  => '-',
        };

        $pic = trim(($r->gelar ?? '') . ' ' . ($r->nama_up ?? ''));
        if (! empty($r->jabatan_up)) {
            $pic .= ' (' . $r->jabatan_up . ')';
        }

        return [
            'nama_customer' => $r->nama_customer,
            'cabang_invoice' => $r->nama_cabang,
            'marketing'     => $r->marketing,
            'top'           => $top,
            'pic'           => $pic !== '' ? $pic : '-',
            'telepon'       => $r->telp_up ?: '-',
            'alamat'        => $r->alamat_up ?: '-',
        ];
    }

    /** Pricelist; tingkat harga yang tampil mengikuti peran (BM, OM, COO, CEO) seperti web. */
    private function pricelist(AclUser $user, object $r): array
    {
        $role = (int) $user->id_role;
        $out = [
            'masa_awal' => $r->masa_awal, 'masa_akhir' => $r->masa_akhir,
            'rekomendasi_harga_dasar' => $r->harga_normal, 'rekomendasi_oa' => $r->oa_kirim,
            'tiers' => [],
        ];
        if (in_array($role, [7, 6, 3, 21], true)) { $out['tiers'][] = ['label' => 'Harga Tier I BM', 'nilai' => $r->harga_sm]; }
        if (in_array($role, [6, 3, 21], true))    { $out['tiers'][] = ['label' => 'Harga Tier II OM', 'nilai' => $r->harga_om]; }
        if (in_array($role, [3, 21], true))       { $out['tiers'][] = ['label' => 'Harga Tier III COO', 'nilai' => $r->harga_coo]; }
        if ($role === 21)                         { $out['tiers'][] = ['label' => 'Harga Tier III CEO', 'nilai' => $r->harga_ceo]; }

        return $out;
    }

    /**
     * Selisih harga dasar penawaran terhadap tiap tier yang boleh dilihat user.
     * Web lama memakai perbandingan ini (harga dasar < harga tier) untuk memutuskan
     * perlu tidaknya diteruskan ke atasan, jadi inilah angka kunci bagi approver.
     */
    private function priceCheck(array $pricelist, array $rincian): array
    {
        $hd = null;
        foreach ($rincian as $r) {
            if (($r['rincian'] ?? '') === 'Harga Dasar') {
                $hd = (float) ($r['biaya'] ?? 0);
                break;
            }
        }

        $items = [];
        foreach ($pricelist['tiers'] as $t) {
            $nilai = (float) $t['nilai'];
            if ($hd === null || $nilai <= 0) {
                continue;
            }
            $items[] = ['label' => $t['label'], 'nilai' => $nilai, 'selisih' => round($hd - $nilai, 2), 'di_bawah' => $hd < $nilai];
        }

        return ['harga_dasar' => $hd, 'items' => $items, 'ringkas' => $items ? end($items) : null];
    }

    private function totals(object $p): array
    {
        $refundTotal = (float) $p->refund_tawar * (float) $p->volume_tawar;
        $otherTotal  = (float) $p->other_cost * (float) $p->volume_tawar;

        return [
            'harga_tier'   => $p->harga_tier,
            'tier'         => $p->tier,
            'refund'       => $p->refund_tawar,
            'refund_total' => $refundTotal,
            'refund_over'  => $refundTotal > config('orca.limits.refund_total'),
            'other_cost'   => $p->other_cost,
            'other_total'  => $otherTotal,
            'other_over'   => $otherTotal > config('orca.limits.refund_total'),
        ];
    }

    private function decode(?string $json): array
    {
        $d = json_decode((string) $json, true);

        return is_array($d) ? $d : [];
    }

    /** Kalimat status approval seperti kolom "Status Approval" di web. */
    private function statusApproval(object $p, ?string $cabang): array
    {
        $pos  = [1 => 'SPV', 2 => 'BM', 3 => 'BM', 4 => 'OM', 5 => 'COO', 6 => 'CEO'];
        $stage = (int) $p->flag_disposisi;
        $flag  = (int) $p->flag_approval;
        $name  = $pos[$stage] ?? '-';
        $label = ($stage > 1 && $stage < 4) ? "{$name} {$cabang}" : $name;

        if ($flag === 0 && $stage === 0) {
            return ['text' => 'Draft', 'tone' => 'wait', 'pic' => null, 'tanggal' => null, 'alasan' => null];
        }
        if ($flag === 0) {
            return ['text' => "Verifikasi {$label}", 'tone' => 'wait', 'pic' => null, 'tanggal' => null, 'alasan' => null];
        }

        $picCol = [3 => 'sm_wil', 4 => 'om', 5 => 'coo', 6 => 'ceo'][$stage] ?? null;
        $alasan = $flag === 2 && $picCol ? ($p->{"{$picCol}_summary"} ?? null) : null;

        return [
            'text'    => ($flag === 1 ? 'Disetujui ' : 'Ditolak ') . $label,
            'tone'    => $flag === 1 ? 'ok' : 'no',
            'pic'     => $picCol ? ($p->{"{$picCol}_pic"} ?? null) : null,
            'tanggal' => $p->tgl_approval,
            'alasan'  => $alasan,
        ];
    }

    /**
     * "History Approval Penawaran": keputusan P001 dikelompokkan per kondisi harga
     * (Pengajuan ke-N), sama seperti web lama (getHargaKey).
     */
    private function historyApproval(object $p): array
    {
        $rows = DB::table('pro_approval_hist as a')
            ->join('acl_user as b', 'a.id_user', '=', 'b.id_user')
            ->join('acl_role as c', 'a.id_role', '=', 'c.id_role')
            ->where('a.id_customer', (string) $p->id_customer)
            ->where('a.id_penawaran', (string) $p->id_penawaran)
            ->where('a.kd_approval', 'P001')
            ->orderBy('a.tgl_approval')
            ->get(['a.result', 'a.summary', 'a.keterangan_pengajuan', 'a.tgl_approval', 'b.fullname',
                   'c.role_name', 'a.harga_dasar', 'a.oa_kirim', 'a.ppn', 'a.pbbkb', 'a.volume',
                   'a.harga_tier', 'a.tier', 'a.refund', 'a.other_cost']);

        $groups = [];
        foreach ($rows as $h) {
            $key = md5(implode('|', [$h->volume, $h->harga_dasar, $h->oa_kirim, $h->ppn, $h->pbbkb,
                                      $h->harga_tier, $h->tier, $h->refund, $h->other_cost]));
            $groups[$key] ??= [
                'harga' => [
                    'volume' => $h->volume, 'harga_dasar' => $h->harga_dasar, 'harga_tier' => $h->harga_tier,
                    'tier' => $h->tier, 'refund' => $h->refund, 'other_cost' => $h->other_cost,
                ],
                'approvals' => [],
            ];
            $groups[$key]['approvals'][] = [
                'hasil'   => (string) $h->result === '1' ? 'Disetujui' : 'Ditolak',
                'oleh'    => $h->fullname,
                'peran'   => str_replace('Role ', '', (string) $h->role_name),
                'tanggal' => $h->tgl_approval,
                'catatan' => $h->summary,
                'keterangan_pengajuan' => $h->keterangan_pengajuan,
            ];
        }

        return array_values($groups);
    }

    /** Penawaran lain milik customer yang sama (web menampilkan hingga 10 dalam akordeon). */
    private function otherPenawaran(object $p): array
    {
        return DB::table('pro_penawaran as a')
            ->join('pro_master_produk as e', 'a.produk_tawar', '=', 'e.id_master')
            ->where('a.id_customer', $p->id_customer)
            ->where('a.id_penawaran', '<>', $p->id_penawaran)
            ->orderByDesc('a.created_time')
            ->limit(9)
            ->get([
                'a.id_penawaran', 'a.nomor_surat', 'a.created_time', 'a.harga_dasar', 'a.volume_tawar',
                'a.masa_awal', 'a.masa_akhir', 'a.flag_disposisi', 'a.flag_approval', 'a.is_edit_harga',
                'e.merk_dagang',
                DB::raw('EXISTS (SELECT 1 FROM pro_po_customer j WHERE j.id_penawaran = a.id_penawaran) as status_po'),
            ])
            ->map(function ($r) {
                $r->tahap = $this->stageLabel($r);
                $r->status_po = (bool) $r->status_po;

                return $r;
            })
            ->all();
    }

    /** Apa yang boleh dipilih user pada form keputusan (mengikuti web lama). */
    private function decisionForm(AclUser $user, object $p, array $warnings): array
    {
        $role      = (int) $user->id_role;
        $needCeo   = $warnings !== [];
        $isBm      = $role === (int) config('orca.roles.bm');
        $isCeo     = $role === (int) config('orca.roles.ceo');
        $isOm      = $role === (int) config('orca.roles.om');

        return [
            'can_forward'     => ! $isCeo,
            'forward_to'      => match (true) {
                $isCeo           => null,
                $isBm && $needCeo => 'CEO',
                $isBm            => 'OM',
                default          => 'CEO',
            },
            // BM dengan warning wajib meneruskan ke CEO bila menyetujui.
            'forward_locked'  => $isBm && $needCeo,
            // OM: tier III default diteruskan ke CEO; selain itu final.
            'forward_default' => ($isBm && $needCeo) || ($isOm && $p->tier === 'III'),
            'reject_requires_note' => true,
        ];
    }

    public function canAct(AclUser $user, object $p): bool
    {
        $role  = (int) $user->id_role;
        $col   = config("orca.role_column.$role");
        $stage = (int) $p->flag_disposisi;

        if (! $col || (int) $p->flag_approval !== 0) {
            return false;
        }
        if ((int) (config('orca.stage_role')[$stage] ?? 0) !== $role) {
            return false;
        }
        if (! empty($p->{"{$col}_result"})) {
            return false;
        }
        if ($role === (int) config('orca.roles.bm') && (int) $p->id_cabang !== (int) $user->id_wilayah) {
            return false;
        }

        return true;
    }

    /** Alasan yang membuat BM wajib meneruskan ke CEO (batas dari web lama). */
    public function warnings(object $p): array
    {
        $w = [];
        $oa = 0.0;
        foreach ($this->rincian($p) as $r) {
            if (($r['rincian'] ?? '') === 'Ongkos Angkut') {
                $oa = (float) ($r['biaya'] ?? 0);
                break;
            }
        }

        if ($oa < (float) $p->oa_kirim && $p->metode === 'Franco') {
            $w[] = ['code' => 'OA_RENDAH', 'message' => 'OA penawaran lebih kecil dari OA yang direkomendasikan.'];
        }
        if ((float) $p->other_cost > config('orca.limits.other_cost')) {
            $w[] = ['code' => 'OTHER_COST', 'message' => 'Other Cost melebihi batas yang ditetapkan.'];
        }
        $refund = (float) $p->refund_tawar;
        if ($refund > config('orca.limits.refund') || $refund * (float) $p->volume_tawar > config('orca.limits.refund_total')) {
            $w[] = ['code' => 'REFUND', 'message' => 'Refund melebihi batas yang ditetapkan.'];
        }

        return $w;
    }

    private function rincian(object $p): array
    {
        $d = json_decode((string) ($p->detail_rincian ?? ''), true);

        return is_array($d) ? $d : [];
    }

    private function stageLabel(object $p): string
    {
        $stage = (int) $p->flag_disposisi;
        $flag  = (int) $p->flag_approval;
        $label = config('orca.stage_label')[$stage] ?? '-';

        if ($flag === 1) {
            return 'Disetujui ' . str_replace('Verifikasi ', '', $label);
        }
        if ($flag === 2) {
            return 'Ditolak ' . str_replace('Verifikasi ', '', $label);
        }

        return $label;
    }

    private function timeline(object $p): array
    {
        $out = [];
        foreach (['sm_mkt' => 'SM Marketing', 'spv_mkt' => 'SPV Marketing', 'sm_wil' => 'BM',
                  'om' => 'OM', 'coo' => 'COO', 'ceo' => 'CEO'] as $col => $label) {
            $pic = $p->{"{$col}_pic"} ?? null;
            $tgl = $p->{"{$col}_tanggal"} ?? null;
            if (! $pic && ! $tgl) {
                continue;
            }
            $out[] = [
                'tahap'   => $label,
                'pic'     => $pic,
                'tanggal' => $tgl,
                'hasil'   => (int) ($p->{"{$col}_result"} ?? 0),
                'catatan' => $p->{"{$col}_summary"} ?? null,
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- keputusan

    /**
     * @param  bool|null  $forward  true = teruskan ke tahap berikut, false = final,
     *                              null = pakai default web lama.
     * @return array{final: bool, next_stage: int|null, message: string}
     */
    public function decide(AclUser $user, int $id, string $decision, ?string $catatan, ?bool $forward): array
    {
        $approve = $decision === 'approve';
        $catatan = trim((string) $catatan);

        if (! $approve && $catatan === '') {
            throw new ApprovalException('Alasan penolakan wajib diisi.', 422, 'NOTE_REQUIRED');
        }

        $notify = null;
        $pushIds = [];
        $accurateManual = false;

        $result = DB::transaction(function () use ($user, $id, $approve, $catatan, $forward, &$notify, &$pushIds, &$accurateManual) {
            $p = DB::table('pro_penawaran')->where('id_penawaran', $id)->lockForUpdate()->first();

            if (! $p) {
                throw new ApprovalException('Penawaran tidak ditemukan.', 404, 'NOT_FOUND');
            }
            if (! $this->canAct($user, $p)) {
                throw new ApprovalException(
                    'Penawaran ini tidak sedang menunggu keputusan Anda (mungkin sudah diproses).',
                    409,
                    'NOT_YOUR_TURN',
                );
            }
            $edit = (int) $p->is_edit_harga === 1;
            $pc = $edit ? $this->priceChange($id, true) : ['request' => null, 'baru' => null, 'lama' => null];
            if ($edit && ! $pc['baru']) {
                throw new ApprovalException('Data perubahan harga baru tidak ditemukan.', 422, 'HARGA_BARU_MISSING');
            }
            $pActive = $edit ? $this->withBaru($p, $pc['baru']) : $p;

            $role = (int) $user->id_role;
            $col  = config("orca.role_column.$role");
            $isBm = $role === (int) config('orca.roles.bm');
            $isOm = $role === (int) config('orca.roles.om');
            $isCeo = $role === (int) config('orca.roles.ceo');

            // Tentukan: diteruskan atau final. Tolak dan CEO selalu final.
            $forwardTo = null; // flag_disposisi tujuan bila diteruskan
            if ($approve && ! $isCeo) {
                $needCeo = $isBm && $this->warnings($pActive) !== [];
                $wantForward = $needCeo
                    ? true                                    // dipaksa server, bukan percaya klien
                    : ($forward ?? ($isOm && $p->tier === 'III'));

                if ($wantForward) {
                    $forwardTo = $isBm && ! $needCeo ? 4 : 6;
                }
            }
            $final = $forwardTo === null;

            $update = [
                "{$col}_summary" => $catatan,
                "{$col}_result"  => $approve ? self::APPROVE : self::REJECT,
                "{$col}_tanggal" => now(),
                "{$col}_pic"     => $user->fullname,
            ];

            if ($forwardTo !== null) {
                $update['flag_disposisi'] = $forwardTo;
            } else {
                $update['flag_approval'] = $approve ? self::APPROVE : self::REJECT;
                $update['tgl_approval']  = now();
                $update['pic_approval']  = $user->id_user;
            }

            DB::table('pro_penawaran')
                ->where('id_penawaran', $id)
                ->where('id_customer', $p->id_customer)
                ->update($update);

            $isCoo = $role === (int) config('orca.roles.coo');
            if ($edit) {
                // Perubahan harga (penawaran-approval (1).php): invoice dicek, harga diterapkan saat final disetujui, riwayat P002 + riwayat request.
                $lama = $this->penawaranLama($id);
                $needInvoiceCheck = match (true) {
                    $isBm => true,
                    $isOm => $forwardTo !== null || $approve,
                    $isCeo => $approve,
                    default => $approve && $final,
                };
                if ($needInvoiceCheck && $this->hasInvoice($id)) {
                    throw new ApprovalException('Gagal diApprove, sudah ada invoice atas penawaran ini.', 409, 'HAS_INVOICE');
                }
                if ($approve && $final) {
                    $accurateManual = $this->applyHargaBaru($id, $pc['baru'], $pc['request']->id_request ?? null, $lama);
                }
                $writeP002 = match (true) {
                    $isOm => $forwardTo !== null || $approve,
                    $isCeo => $approve,
                    default => true,
                };
                if ($writeP002) {
                    // Sesuai web: saat OM meneruskan, keterangan pengajuan diambil dari catatan penawaran lama.
                    $ket = ($isOm && $forwardTo !== null) ? $lama['catatan'] : ($pc['baru']->catatan ?? '');
                    $this->writeHistoryBaru($user, $p, $approve, $catatan, $pc['baru'], $lama, (string) $ket);
                }
                if ($pc['request']) {
                    $level = $isBm ? 'BM' : ($isOm ? 'OM' : ($isCoo ? 'COO' : 'CEO'));
                    $next = $forwardTo === 4 ? 'OM' : ($forwardTo === 6 ? 'CEO' : null);
                    $this->updateRequest($pc['request'], $id, $level, $approve, $catatan, $user, $final, $next);
                }
                if ($approve && $final) {
                    DB::table('pro_penawaran')->where('id_penawaran', $id)->where('flag_approval', 1)
                        ->whereRaw("UPPER(TRIM(COALESCE(tipe_penawaran, ''))) = 'SEMENTARA'")->update(['tipe_penawaran' => 'FINAL']);
                }
            } else {
                // Riwayat pro_approval_hist mengikuti web lama: BM dan COO selalu;
                // OM saat diteruskan atau saat final disetujui; CEO hanya bila disetujui.
                $writeHist = match (true) {
                    $isBm || $isCoo => true,
                    $isOm  => $forwardTo !== null || $approve,
                    $isCeo => $approve,
                    default => false,
                };
                if ($writeHist) {
                    $this->writeHistory($user, $p, $approve, $catatan);
                }
            }

            if (! $approve) {
                DB::table('pro_penawaran')->where('id_penawaran', $id)->update(['view' => 'No']);
            }

            $notify = $this->recipients($user, $p, $forwardTo, $approve);
            $pushIds = $this->pushRecipients($user, $p, $forwardTo);

            return ['final' => $final, 'next_stage' => $forwardTo];
        });

        $result['accurate_manual'] = $accurateManual;
        $this->sendEmail($notify, $user, $id, $approve, $result['final']);
        $this->sendPush($pushIds, $user, $id, $approve, $result['final']);

        $result['message'] = match (true) {
            ! $approve => 'Penawaran ditolak.',
            $result['final'] => 'Penawaran disetujui.',
            default => 'Penawaran disetujui dan diteruskan ke ' . ($result['next_stage'] === 4 ? 'OM' : 'CEO') . '.',
        };

        return $result;
    }

    // ----------------------------------------------------- perubahan harga

    /** Request perubahan harga aktif (WAITING_APPROVAL) + snapshot BARU/LAMA; data lama tanpa id_request tetap didukung. */
    public function priceChange(int $id, bool $lock = false): array
    {
        $rq = DB::table('pro_penawaran_perubahan_harga_request')->where('id_penawaran', $id)->where('status_pengajuan', 'WAITING_APPROVAL')->orderByDesc('id_request');
        if ($lock) {
            $rq->lockForUpdate();
        }
        $req = $rq->first();
        $idReq = $req->id_request ?? null;
        $snap = fn (string $tipe) => DB::table('pro_penawaran_perubahan_harga')->where('id_penawaran', $id)->where('tipe', $tipe)
            ->when($idReq, fn ($q) => $q->where('id_request', $idReq), fn ($q) => $q->whereNull('id_request'))->orderByDesc('id')->first();

        return ['request' => $req, 'baru' => $snap('BARU'), 'lama' => $snap('LAMA')];
    }

    /** Penawaran dengan nilai harga dari pengajuan baru (dipakai untuk peringatan BM, sama dengan "aktif" di halaman web). */
    private function withBaru(object $p, object $baru): object
    {
        $c = clone $p;
        foreach (['harga_dasar', 'harga_asli', 'detail_rincian', 'refund_tawar', 'other_cost', 'harga_tier', 'tier', 'pembulatan', 'gabung_oa', 'all_in', 'gabung_pbbkb', 'gabung_pbbkboa', 'catatan'] as $k) {
            if (isset($baru->$k)) {
                $c->$k = $baru->$k;
            }
        }

        return $c;
    }

    private static function rincianValues(?string $json): array
    {
        $v = ['harga_dasar' => 0.0, 'oa' => 0.0, 'ppn' => 0.0, 'pbbkb' => 0.0];
        $map = ['Harga Dasar' => 'harga_dasar', 'Ongkos Angkut' => 'oa', 'PPN' => 'ppn', 'PBBKB' => 'pbbkb'];
        foreach (json_decode((string) $json, true) ?: [] as $r) {
            if (isset($map[$r['rincian'] ?? ''])) {
                $v[$map[$r['rincian']]] = (float) ($r['biaya'] ?? 0);
            }
        }

        return $v;
    }

    private function penawaranLama(int $id): array
    {
        $r = DB::table('pro_penawaran')->where('id_penawaran', $id)->first(['id_customer', 'id_cabang', 'volume_tawar', 'catatan', 'refund_tawar', 'other_cost', 'oa_kirim', 'gabung_oa', 'gabung_pbbkb', 'all_in', 'gabung_pbbkboa']);
        if (! $r) {
            throw new ApprovalException('Data penawaran tidak ditemukan.', 404, 'NOT_FOUND');
        }
        $jenis = (int) $r->gabung_oa === 1 ? 'gabung_oa' : ((int) $r->gabung_pbbkb === 1 ? 'gabung_pbbkb' : (((int) $r->all_in === 1 || (int) $r->gabung_pbbkboa === 1) ? 'all_in' : 'break_all'));

        return (array) $r + ['jenis_penawaran' => $jenis];
    }

    /** Ada invoice atas penawaran ini: perubahan harga tidak boleh disetujui. */
    private function hasInvoice(int $id): bool
    {
        return DB::table('pro_po_customer as a')->join('pro_po_customer_plan as b', 'b.id_poc', '=', 'a.id_poc')
            ->join('pro_po_ds_detail as c', 'a.id_poc', '=', 'c.id_poc')->join('pro_invoice_admin_detail as d', 'c.id_dsd', '=', 'd.id_dsd')
            ->where('a.id_penawaran', $id)->exists();
    }

    /**
     * Terapkan harga baru (approvalPenawaranApplyHargaBaru): credit limit reserved, Sales Order Accurate (bila aktif), penawaran, harga PO customer, Other Cost.
     *
     * @return bool true bila ada Sales Order Accurate yang perlu diubah manual (Accurate belum aktif)
     */
    private function applyHargaBaru(int $id, object $baru, ?int $idRequest, array $lama): bool
    {
        $tot = DB::table('pro_po_customer')->where('id_penawaran', $id)->where('poc_approved', 1)
            ->selectRaw('COALESCE(SUM(harga_poc * volume_poc), 0) as total_lama, COALESCE(SUM(volume_poc), 0) as vol')->first();
        $delta = (float) $baru->harga_dasar * (float) $tot->vol - (float) $tot->total_lama;
        DB::table('pro_customer')->where('id_customer', $lama['id_customer'])->update(['credit_limit_reserved' => DB::raw('COALESCE(credit_limit_reserved, 0) + '.(float) $delta)]);

        $namaCabang = DB::table('pro_master_cabang')->where('id_master', $lama['id_cabang'])->value('nama_cabang') ?? '';
        $branch = $namaCabang === 'Kantor Pusat' ? 'Head Office' : $namaCabang;
        $sync = app(AccurateSoPriceSync::class);
        $manual = false;
        try {
            if ($sync->enabled()) {
                $sync->sync($id, $branch, $lama['jenis_penawaran'], self::rincianValues($baru->detail_rincian));
            } else {
                $manual = $sync->salesOrderIds($id) !== [];
            }
        } catch (AccurateException $e) {
            throw new ApprovalException($e->getMessage(), 502, 'ACCURATE');
        }

        DB::table('pro_penawaran')->where('id_penawaran', $id)->update([
            'harga_dasar' => $baru->harga_dasar, 'harga_asli' => $baru->harga_asli, 'detail_rincian' => $baru->detail_rincian,
            'refund_tawar' => $baru->refund_tawar, 'other_cost' => $baru->other_cost, 'harga_tier' => $baru->harga_tier, 'tier' => $baru->tier,
            'lastupdate_time' => now(), 'lastupdate_by' => $baru->created_by, 'pembulatan' => $baru->pembulatan, 'gabung_oa' => $baru->gabung_oa,
            'all_in' => $baru->all_in, 'gabung_pbbkb' => $baru->gabung_pbbkb, 'gabung_pbbkboa' => $baru->gabung_pbbkboa, 'catatan' => $baru->catatan ?? '',
        ]);
        DB::table('pro_po_customer')->where('id_penawaran', $id)->update(['harga_poc' => $baru->harga_dasar]);

        // Other Cost: ganti seluruhnya dengan yang dari pengajuan (tambah, ubah, hapus sebagian, atau hapus semua).
        if (Schema::hasTable('pro_other_cost_detail_perubahan_harga')) {
            DB::table('pro_other_cost_detail')->where('id_penawaran', $id)->delete();
            $rows = DB::table('pro_other_cost_detail_perubahan_harga')->where('id_penawaran', $id)->where('tipe', 'BARU')
                ->when($idRequest, fn ($q) => $q->where('id_request', $idRequest), fn ($q) => $q->whereNull('id_request'))->get(['id_penawaran', 'keterangan', 'nominal']);
            foreach ($rows as $r) {
                DB::table('pro_other_cost_detail')->insert(['id_penawaran' => $r->id_penawaran, 'keterangan' => $r->keterangan, 'nominal' => $r->nominal, 'created_time' => now()]);
            }
        }

        return $manual;
    }

    /** Riwayat P002 (pro_approval_hist) untuk keputusan atas pengajuan perubahan harga. */
    private function writeHistoryBaru(AclUser $user, object $p, bool $approve, string $catatan, object $baru, array $lama, string $keterangan): void
    {
        $v = self::rincianValues($baru->detail_rincian);
        DB::table('pro_approval_hist')->insert([
            'kd_approval' => 'P002', 'result' => $approve ? '1' : '2', 'summary' => $catatan, 'id_user' => (string) $user->id_user, 'tgl_approval' => now(),
            'id_customer' => (string) $p->id_customer, 'id_penawaran' => (string) $p->id_penawaran, 'id_role' => (string) $user->id_role,
            'harga_dasar' => (int) round((float) $baru->harga_asli), 'harga_tier' => (int) $baru->harga_tier, 'tier' => $baru->tier,
            'refund' => (int) ($baru->refund_tawar ?? 0), 'other_cost' => (int) ($baru->other_cost ?? 0), 'oa_kirim' => (int) $lama['oa_kirim'],
            'pbbkb' => (int) round($v['pbbkb']), 'ppn' => (int) round($v['ppn']), 'keterangan_pengajuan' => $keterangan, 'volume' => (int) $lama['volume_tawar'],
        ]);
    }

    /** Catat keputusan di riwayat request lalu perbarui header request (ditolak / disetujui final / diteruskan). */
    private function updateRequest(object $req, int $id, string $level, bool $approve, string $catatan, AclUser $user, bool $final, ?string $next): void
    {
        $step = (int) DB::table('pro_penawaran_perubahan_harga_approval_history')->where('id_request', $req->id_request)->max('approval_step') + 1;
        DB::table('pro_penawaran_perubahan_harga_approval_history')->insert([
            'id_request' => $req->id_request, 'id_penawaran' => $id, 'approval_step' => $step, 'approval_level' => $level, 'result' => $approve ? 1 : 2,
            'summary' => $catatan, 'approved_by' => $user->id_user, 'approved_name' => $user->fullname, 'approved_time' => now(), 'created_time' => now(),
        ]);
        $q = DB::table('pro_penawaran_perubahan_harga_request')->where('id_request', $req->id_request)->where('status_pengajuan', 'WAITING_APPROVAL');
        if (! $approve) {
            $q->update(['status_pengajuan' => 'REJECTED', 'current_approval' => null, 'finished_by' => $user->id_user, 'finished_name' => $user->fullname, 'finished_time' => now(), 'updated_time' => now()]);
            DB::table('pro_penawaran')->where('id_penawaran', $id)->update(['is_edit_harga' => 0]); // boleh diajukan ulang
        } elseif ($final) {
            $q->update(['status_pengajuan' => 'APPROVED', 'current_approval' => null, 'finished_by' => $user->id_user, 'finished_name' => $user->fullname, 'finished_time' => now(), 'updated_time' => now()]);
        } elseif ($next) {
            $q->update(['current_approval' => $next, 'updated_time' => now()]);
        }
    }

    /** Bagian "Pengajuan perubahan harga" pada detail: perbandingan lama vs baru + riwayat request. Null bila tidak ada pengajuan. */
    private function priceChangeView(object $p): ?array
    {
        $isEdit = (int) $p->is_edit_harga === 1;
        $pc = $this->priceChange((int) $p->id_penawaran);
        $hasHistory = DB::table('pro_penawaran_perubahan_harga_request')->where('id_penawaran', $p->id_penawaran)->exists();
        if (! $isEdit && ! $pc['request'] && ! $hasHistory) {
            return null;
        }
        $idReq = $pc['request']->id_request ?? null;
        $oc = fn (?int $req, string $tipe) => Schema::hasTable('pro_other_cost_detail_perubahan_harga')
            ? DB::table('pro_other_cost_detail_perubahan_harga')->where('id_penawaran', $p->id_penawaran)->where('tipe', $tipe)
                ->when($req, fn ($q) => $q->where('id_request', $req), fn ($q) => $q->whereNull('id_request'))->orderBy('id_master')->get(['keterangan', 'nominal'])->all()
            : [];
        $snap = fn (?object $s, array $others) => $s ? [
            'harga_dasar' => (float) $s->harga_dasar, 'harga_asli' => (float) $s->harga_asli, 'harga_tier' => (int) $s->harga_tier, 'tier' => $s->tier,
            'refund' => (float) $s->refund_tawar, 'other_cost' => (float) $s->other_cost, 'pembulatan' => (int) $s->pembulatan,
            'rincian' => json_decode((string) $s->detail_rincian, true) ?: [], 'other_costs' => $others,
            'catatan' => html_entity_decode(str_replace(['<br />', '<br>'], "\n", (string) ($s->catatan ?? '')), ENT_QUOTES),
        ] : null;

        $requests = DB::table('pro_penawaran_perubahan_harga_request')->where('id_penawaran', $p->id_penawaran)->orderByDesc('id_request')->get();
        $snapOf = fn (int $req, string $tipe) => $snap(
            DB::table('pro_penawaran_perubahan_harga')->where('id_penawaran', $p->id_penawaran)->where('id_request', $req)->where('tipe', $tipe)->orderByDesc('id')->first(),
            $oc($req, $tipe),
        );
        $riwayat = $requests->map(fn ($r) => [
            'lama' => $snapOf((int) $r->id_request, 'LAMA'), 'baru' => $snapOf((int) $r->id_request, 'BARU'),
            'id' => (int) $r->id_request, 'pengajuan_ke' => (int) $r->pengajuan_ke, 'tier' => $r->tier, 'status' => $r->status_pengajuan,
            'posisi' => $r->current_approval, 'diajukan_oleh' => $r->submitted_name, 'diajukan_pada' => $r->submitted_time,
            'selesai_oleh' => $r->finished_name, 'selesai_pada' => $r->finished_time,
            'approvals' => DB::table('pro_penawaran_perubahan_harga_approval_history')->where('id_request', $r->id_request)->orderBy('approval_step')
                ->get()->map(fn ($a) => ['level' => $a->approval_level, 'hasil' => (int) $a->result, 'nama' => $a->approved_name, 'waktu' => $a->approved_time, 'catatan' => $a->summary])->all(),
        ])->all();

        return [
            'aktif' => (bool) $pc['request'] || $isEdit,
            'pengajuan_ke' => $pc['request'] ? (int) $pc['request']->pengajuan_ke : null,
            'diajukan_oleh' => $pc['request']->submitted_name ?? null, 'diajukan_pada' => $pc['request']->submitted_time ?? null,
            'posisi' => $pc['request']->current_approval ?? null, 'status' => $pc['request']->status_pengajuan ?? null,
            'lama' => $snap($pc['lama'], $oc($idReq, 'LAMA')), 'baru' => $snap($pc['baru'], $oc($idReq, 'BARU')),
            'riwayat' => $riwayat, '_baru' => $pc['baru'],
        ];
    }

    private function writeHistory(AclUser $user, object $p, bool $approve, string $catatan): void
    {
        $ppn = $pbbkb = 0;
        foreach ($this->rincian($p) as $r) {
            if (($r['rincian'] ?? '') === 'PPN') {
                $ppn = (int) round((float) ($r['biaya'] ?? 0));
            } elseif (($r['rincian'] ?? '') === 'PBBKB') {
                $pbbkb = (int) round((float) ($r['biaya'] ?? 0));
            }
        }

        DB::table('pro_approval_hist')->insert([
            'kd_approval'          => 'P001',
            'result'               => $approve ? '1' : '2',
            'summary'              => $catatan,
            'id_user'              => (string) $user->id_user,
            'tgl_approval'         => now(),
            'id_customer'          => (string) $p->id_customer,
            'id_penawaran'         => (string) $p->id_penawaran,
            'id_role'              => (string) $user->id_role,
            'harga_dasar'          => (int) round((float) $p->harga_dasar),
            'harga_tier'           => (int) ($p->harga_tier ?? 0),
            'tier'                 => $p->tier,
            'refund'               => (int) round((float) $p->refund_tawar),
            'other_cost'           => (int) round((float) $p->other_cost),
            'oa_kirim'             => (int) $p->oa_kirim,
            'pbbkb'                => $pbbkb,
            'ppn'                  => $ppn,
            'keterangan_pengajuan' => '',
            'volume'               => (int) $p->volume_tawar,
        ]);
    }

    // ------------------------------------------------------------------ email

    /** Penerima email setelah keputusan (sama dengan web lama). */
    private function recipients(AclUser $user, object $p, ?int $forwardTo, bool $approve): array
    {
        if ($forwardTo === 6) {
            return DB::table('acl_user')->where('id_role', config('orca.roles.ceo'))->pluck('email_user')->all();
        }
        if ($forwardTo === 4) {
            return DB::table('acl_user')
                ->where('id_role', config('orca.roles.om'))
                ->where('id_group', $user->id_group)
                ->pluck('email_user')->all();
        }

        // Final: kabari marketing pemilik customer.
        return DB::table('pro_customer as b')
            ->join('acl_user as c', 'b.id_marketing', '=', 'c.id_user')
            ->where('b.id_customer', $p->id_customer)
            ->pluck('c.email_user')->all();
    }

    private function sendEmail(?array $to, AclUser $user, int $id, bool $approve, bool $final): void
    {
        $to = array_values(array_filter($to ?? []));
        if ($to === []) {
            return;
        }

        try {
            $p = DB::table('pro_penawaran as a')
                ->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')
                ->where('a.id_penawaran', $id)
                ->first(['a.nomor_surat', 'b.nama_customer']);

            $status = ! $final ? 'menunggu persetujuan Anda' : ($approve ? 'telah disetujui' : 'ditolak');
            $subject = "Penawaran {$p->nomor_surat} {$status}";
            $body = '<p>Penawaran <b>' . e($p->nomor_surat) . '</b> untuk <b>' . e($p->nama_customer)
                . '</b> ' . e($status) . '. Diproses oleh ' . e($user->fullname) . '.</p>';

            Mail::html($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            // Email gagal tidak boleh membatalkan approval (sama seperti web lama).
            Log::warning('[Approval] Email gagal dikirim: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------- push

    /** id_user yang perlu diberi notifikasi push (sama dengan penerima email). */
    private function pushRecipients(AclUser $user, object $p, ?int $forwardTo): array
    {
        if ($forwardTo === 6) {
            return DB::table('acl_user')->where('id_role', config('orca.roles.ceo'))->pluck('id_user')->all();
        }
        if ($forwardTo === 4) {
            return DB::table('acl_user')
                ->where('id_role', config('orca.roles.om'))
                ->where('id_group', $user->id_group)
                ->pluck('id_user')->all();
        }

        return DB::table('pro_customer')->where('id_customer', $p->id_customer)->pluck('id_marketing')->all();
    }

    /** Isi notifikasi sengaja singkat: tanpa harga atau data sensitif (lewat layanan push pihak ketiga). */
    private function sendPush(array $ids, AclUser $user, int $id, bool $approve, bool $final): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $p = DB::table('pro_penawaran as a')
                ->join('pro_customer as b', 'a.id_customer', '=', 'b.id_customer')
                ->where('a.id_penawaran', $id)
                ->first(['a.nomor_surat', 'b.nama_customer']);

            [$title, $titleEn, $url] = ! $final
                ? ['Penawaran menunggu persetujuan Anda', 'Offer awaiting your approval', "/approval/{$id}"]
                : [$approve ? 'Penawaran disetujui' : 'Penawaran ditolak', $approve ? 'Offer approved' : 'Offer rejected', '/riwayat'];

            $body = "{$p->nama_customer} · {$p->nomor_surat}";
            app(PushService::class)->send($ids, [
                'title' => $title,
                'body'  => $body,
                'url'   => $url,
                'tag'   => "penawaran-{$id}",
                'en'    => ['title' => $titleEn, 'body' => $body],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Approval] Push gagal: ' . $e->getMessage());
        }
    }
}
