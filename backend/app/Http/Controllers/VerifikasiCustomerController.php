<?php

namespace App\Http\Controllers;

use App\Models\AclUser;
use App\Services\Accurate\AccurateClient;
use App\Services\ApprovalMail;
use App\Services\LegacyFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi Data Customer untuk Branch Manager (peran 7) dan Operation Manager (peran 6).
 * Sumber: verifikasi-data-customer.php (daftar), verifikasi-data-customer-detail.php, __get_data_customer_sm.php / _om.php (form),
 * evaluation-prospective.php (aksi simpan BM/OM + pembuatan customer di Accurate).
 *
 * disposisi_result: 1 Tahap Verifikasi (Finance + Logistik), 2 Verifikasi BM, 3 Verifikasi OM, 4 CFO, 5 CEO, 6 MGR Finance.
 * BM menyetujui = keputusan akhir (disposisi 3, is_approved 1), sama seperti web saat ini. OM menyetujui hanya bila data masih menunggu OM.
 * Menolak (BM/OM): is_approved 2, email penolakan ke marketing.
 * Setelah disetujui, customer dibuat di Accurate (customer/save.do, kode TEMP<n>); gagal = seluruh keputusan dibatalkan.
 * Lampiran (KYC, review) hanya ditampilkan namanya: file ada di server web lama.
 */
class VerifikasiCustomerController extends Controller
{
    private const POSISI = [2 => 'BM', 3 => 'OM', 4 => 'CFO', 5 => 'CEO', 6 => 'MGR Finance'];
    private const ROLE = [7 => 'BM', 6 => 'OM', 3 => 'CEO', 4 => 'CFO', 15 => 'MGR Finance'];
    private const TIPE_BISNIS = [
        1 => 'Agriculture & Forestry / Horticulture', 2 => 'Business & Information', 3 => 'Construction/Utilities/Contracting', 4 => 'Education',
        5 => 'Finance & Insurance', 6 => 'Food & hospitally', 7 => 'Gaming', 8 => 'Health Services', 9 => 'Motor Vehicle', 10 => null,
        11 => 'Natural Resources / Environmental', 12 => 'Personal Service', 13 => 'Manufacture',
    ];
    private const HASIL = [1 => 'Supply Delivery', 2 => 'Supply Delivery With Note', 3 => 'Revised and Resubmitted', 4 => 'Yes', 5 => 'No'];
    private const SETUJU = [1 => 'Yes', 2 => 'No'];

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }

    /** Daftar dasar: verifikasi yang sudah dievaluasi dan direview, dibatasi menurut peran (BM: wilayahnya, OM: grupnya / marketing di bawahnya). */
    private function base(AclUser $u)
    {
        $q = DB::table('pro_customer as a')
            ->join('pro_master_provinsi as b', 'a.prov_customer', '=', 'b.id_prov')
            ->join('pro_master_kabupaten as c', 'a.kab_customer', '=', 'c.id_kab')
            ->join('acl_user as d', 'a.id_marketing', '=', 'd.id_user')
            ->join('pro_customer_verification as e', fn ($j) => $j->on('a.id_customer', '=', 'e.id_customer')->where('e.is_evaluated', 1)->where('e.is_reviewed', 1))
            ->join('pro_master_cabang as f', 'a.id_wilayah', '=', 'f.id_master')
            ->join('pro_customer_review as g', 'e.id_verification', '=', 'g.id_verification');

        if ((int) $u->id_role === 7) {
            $q->where('a.id_wilayah', $u->id_wilayah);
        } else {
            $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereIn('d.id_role', [11, 18])->where('a.id_group', $u->id_group))
                ->orWhere(fn ($x) => $x->where('d.id_role', 17)->where('d.id_om', $u->id_user)));
        }

        return $q;
    }

    /** Kondisi "menunggu keputusan" untuk peran ini (ordernya = 1 di web). */
    private function waiting($q, AclUser $u)
    {
        if ((int) $u->id_role === 7) {
            return $q->where('e.disposisi_result', 2)->where('e.sm_result', 0)->where('e.is_approved', 0);
        }

        return $q->where('e.disposisi_result', 3)->where('e.om_result', 0)->where('e.is_approved', 0);
    }

    public function waitingCount(AclUser $u): int
    {
        return (int) $this->waiting($this->base($u), $u)->distinct()->count('e.id_verification');
    }

    /** @return array<int,string> id_verification => LC0000 nama */
    public function waitingItems(AclUser $u): array
    {
        return $this->waiting($this->base($u), $u)->orderByDesc('e.id_verification')->limit(500)
            ->get(['e.id_verification as id', 'a.nama_customer as nama'])
            ->mapWithKeys(fn ($x) => [(int) $x->id => self::kode((int) $x->id).' - '.$x->nama])->all();
    }

    private static function kode(int $id): string
    {
        return 'LC'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    private static function status(object $x): string
    {
        $tgl = $x->tanggal_approved ? date('d/m/Y H:i:s', strtotime($x->tanggal_approved)) : '';
        if ((int) $x->is_approved === 1 || (int) $x->is_approved === 2) {
            $kata = (int) $x->is_approved === 1 ? 'Disetujui' : 'Ditolak';
            $oleh = $x->role_approve ? (self::ROLE[(int) $x->role_approve] ?? '') : (self::POSISI[(int) $x->disposisi_result] ?? '');

            return trim("$kata $oleh").($tgl ? " ($tgl)" : '');
        }
        $d = (int) $x->disposisi_result;
        if ($d === 1) {
            $tahap = array_filter([(int) $x->finance_result ? null : 'Admin Finance', (int) $x->logistik_result ? null : 'Logistics']);

            return 'Tahap Verifikasi'.($tahap ? ' ('.implode(', ', $tahap).')' : '');
        }

        return isset(self::POSISI[$d]) ? 'Verifikasi '.self::POSISI[$d] : '-';
    }

    public function index(Request $r): JsonResponse
    {
        $u = $r->user();
        $q = $this->base($u);

        if (($term = trim((string) $r->query('q', ''))) !== '') {
            $like = '%'.$term.'%';
            $q->where(fn ($w) => $w->where('a.nama_customer', 'like', $like)->orWhere('a.kode_pelanggan', $term)
                ->orWhereRaw("concat('LC',lpad(e.id_verification,4,'0')) = ?", [strtoupper($term)]));
        }
        if ($r->query('filter') === 'menunggu') {
            $this->waiting($q, $u);
        }

        $pending = (int) $u->id_role === 7
            ? '(e.disposisi_result = 2 and e.sm_result = 0 and e.is_approved = 0)'
            : '(e.disposisi_result = 3 and e.om_result = 0 and e.is_approved = 0)';

        $page = $q->selectRaw("case when $pending then 1 else 3 end as ordernya")
            ->addSelect(['a.nama_customer', 'a.alamat_customer', 'a.kode_pelanggan', 'b.nama_prov', 'c.nama_kab', 'd.fullname', 'f.nama_cabang',
                'e.id_verification', 'e.is_approved', 'e.finance_result', 'e.logistik_result', 'e.disposisi_result', 'e.role_approve', 'e.tanggal_approved'])
            ->orderBy('ordernya')->orderByDesc('g.id_review')
            ->paginate(min(50, max(1, (int) $r->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($x) => [
                'id' => (int) $x->id_verification, 'kode' => self::kode((int) $x->id_verification), 'kode_pelanggan' => $x->kode_pelanggan,
                'customer' => $x->nama_customer, 'marketing' => $x->fullname, 'cabang' => $x->nama_cabang,
                'alamat' => trim($x->alamat_customer.' '.ucwords(strtolower(str_replace(['KABUPATEN ', 'KOTA '], '', (string) $x->nama_kab))).' '.$x->nama_prov),
                'status' => self::status($x), 'menunggu' => (int) $x->ordernya === 1, 'disetujui' => (int) $x->is_approved,
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function find(Request $r, int $id): ?object
    {
        return $this->base($r->user())->where('e.id_verification', $id)->first(['e.id_verification']);
    }

    public function show(Request $r, int $id): JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data verifikasi tidak ditemukan.'], 404);
        }
        $u = $r->user();

        $x = DB::table('pro_customer as a')
            ->leftJoin('pro_customer_contact as b', 'a.id_customer', '=', 'b.id_customer')
            ->leftJoin('pro_customer_payment as d', 'a.id_customer', '=', 'd.id_customer')
            ->leftJoin('pro_customer_logistik as e', 'a.id_customer', '=', 'e.id_customer')
            ->leftJoin('pro_master_provinsi as f', 'a.prov_customer', '=', 'f.id_prov')
            ->leftJoin('pro_master_kabupaten as g', 'a.kab_customer', '=', 'g.id_kab')
            ->leftJoin('pro_master_provinsi as h', 'd.prov_billing', '=', 'h.id_prov')
            ->leftJoin('pro_master_kabupaten as i', 'd.kab_billing', '=', 'i.id_kab')
            ->join('pro_customer_verification as j', 'a.id_customer', '=', 'j.id_customer')
            ->leftJoin('pro_customer_review as l', 'j.id_verification', '=', 'l.id_verification')
            ->leftJoin('pro_master_cabang as k', fn ($q) => $q->on('a.id_wilayah', '=', 'k.id_master')->on('a.id_group', '=', 'k.id_group_cabang'))
            ->leftJoin('acl_user as m', 'a.id_marketing', '=', 'm.id_user')
            ->where('j.id_verification', $id)
            ->selectRaw('a.*, b.*, d.email_billing, d.alamat_billing, d.telp_billing, d.fax_billing, d.postalcode_billing, d.kecamatan_billing, d.kelurahan_billing,
                d.payment_method, d.payment_method_other, d.calculate_method, d.bank_name, d.account_number, d.curency, d.bank_address, d.credit_facility, d.creditor,
                f.nama_prov as propinsi_customer, g.nama_kab as kabupaten_customer, h.nama_prov as propinsi_payment, i.nama_kab as kabupaten_payment,
                e.logistik_area, e.supply_shceme, e.specify_product, e.nico, e.volume_per_month, e.operational_hour_from, e.operational_hour_to,
                j.finance_data, j.finance_summary, j.finance_result, j.finance_tgl_proses, j.finance_pic, j.finance_data_kyc,
                j.logistik_summary, j.logistik_result, j.logistik_tgl_proses, j.logistik_pic,
                j.sm_summary, j.sm_result, j.sm_tgl_proses, j.sm_pic, j.om_summary, j.om_result, j.om_tgl_proses, j.om_pic,
                j.disposisi_result, j.is_approved, j.role_approve, j.tanggal_approved, j.legal_data,
                k.nama_cabang as wilayah, m.fullname as marketing,
                l.id_review, l.review1, l.review2, l.review3, l.review4, l.review5, l.review6, l.review7, l.review8, l.review9, l.review10, l.review11, l.review12,
                l.review13, l.review14, l.review15, l.review16, l.review_summary, l.jenis_asset, l.kelengkapan_dok_tagihan, l.alur_proses_periksaan,
                l.jadwal_penerimaan, l.background_bisnis, l.lokasi_depo, l.opportunity_bisnis')
            ->first();

        $tipe = (int) $x->tipe_bisnis === 10 ? $x->tipe_bisnis_lain : (self::TIPE_BISNIS[(int) $x->tipe_bisnis] ?? null);
        $kond = [1 => 'After Invoice Receive', 2 => 'After Delivery', 3 => 'After Loading'];
        $top = match ($x->jenis_payment) {
            'CREDIT' => 'CREDIT '.$x->top_payment.' days'.((int) $x->jenis_net > 0 ? ' '.($kond[(int) $x->jenis_net] ?? '') : ''),
            'CBD' => 'CBD (Cash Before Delivery)', 'COD' => 'COD (Cash On Delivery)', default => '-',
        };
        $legal = json_decode((string) $x->legal_data, true) ?: [];
        $kyc = collect(array_values(json_decode((string) $x->finance_data_kyc, true) ?: []))->map(fn ($k, $i) => [
            'idx' => $i, 'nama' => $k['nama_file'] ?? '', 'file' => $k['file_upload_ori'] ?? '',
        ])->filter(fn ($k) => $k['nama'] !== '')->values()->all();
        $lampiranReview = DB::table('pro_customer_review_attchment')->where('id_review', $x->id_review)->where('id_verification', $id)
            ->orderBy('no_urut')->get(['no_urut', 'review_attach_ori'])->filter(fn ($f) => $f->review_attach_ori)
            ->map(fn ($f) => ['no' => (int) $f->no_urut, 'nama' => $f->review_attach_ori])->values()->all();
        $ownership = [1 => 'Affiliation', 2 => 'National Private', 3 => 'Foreign Private', 4 => 'Joint Venture', 5 => 'BUMN / BUMD', 6 => 'Foundation', 7 => 'Personal', 8 => $x->ownership_lain][(int) $x->ownership] ?? '-';
        $x->ownership_label = $ownership;

        $tahap = fn ($ringkas, $hasil, $pic, $tgl, array $peta) => [
            'ringkasan' => $ringkas, 'hasil' => $hasil ? ($peta[(int) $hasil] ?? null) : null, 'oleh' => $pic ?: null,
            'tanggal' => $tgl && $tgl !== '0000-00-00 00:00:00' ? date('d/m/Y H:i:s', strtotime($tgl)) : null,
        ];

        $role = (int) $u->id_role;
        $bisaPutus = $role === 7
            ? ((int) $x->disposisi_result === 2 && (int) $x->sm_result === 0 && (int) $x->is_approved === 0)
            : ((int) $x->disposisi_result === 3 && (int) $x->om_result === 0 && (int) $x->is_approved === 0);

        return response()->json(['data' => [
            'id' => $id, 'kode' => self::kode($id), 'status' => self::status($x), 'bisa_putus' => $bisaPutus,
            'evaluasi' => [
                'customer' => $x->nama_customer, 'tipe_bisnis' => $tipe ?: '-', 'top_diajukan' => $top,
                'volume_potensial' => $x->review9, 'kredit_diajukan' => (int) $x->credit_limit_diajukan,
                'kredit_disetujui' => (int) $x->credit_limit, 'tipe_pembayaran' => $x->jenis_payment, 'top_hari' => (int) $x->top_payment,
                'jenis_net' => $kond[(int) $x->jenis_net] ?? null, 'kyc' => $kyc,
                'finance' => $tahap($x->finance_summary, $x->finance_result, $x->finance_pic, $x->finance_tgl_proses, self::HASIL),
                'logistik' => $tahap($x->logistik_summary, $x->logistik_result, $x->logistik_pic, $x->logistik_tgl_proses, self::HASIL),
                'bm' => $tahap($x->sm_summary, $x->sm_result, $x->sm_pic, $x->sm_tgl_proses, self::SETUJU),
                'om' => $tahap($x->om_summary, $x->om_result, $x->om_pic, $x->om_tgl_proses, self::SETUJU),
            ],
            'customer' => $this->dataCustomer($x, $legal),
            'review' => $this->review($x, $tipe, $lampiranReview),
        ]]);
    }


    private static function kab(?string $v): string
    {
        return ucwords(strtolower(str_replace(['KABUPATEN ', 'KOTA '], '', (string) $v)));
    }

    /** Tab "Data Customer": Application Customer Form (bagian 1 s/d 4) seperti __get_data_customer.php. */
    private function dataCustomer(object $x, array $legal): array
    {
        $pd = json_decode((string) $x->product_delivery_address, true);
        $pd = is_array($pd) ? ($pd['product_delivery_address'] ?? []) : [];
        $kond = [1 => 'After Invoice Receive', 2 => 'After Delivery', 3 => 'After loading'];
        $metode = [1 => 'Cash', 2 => 'Transfer', 3 => 'Cheque / Giro', 4 => 'Bank Guarantee', 5 => $x->payment_method_other];
        $alamat = fn ($a, $kel, $kec, $kab, $prov, $pos, $telp, $fax) => [
            'alamat' => $a, 'kelurahan' => $kel, 'kecamatan' => $kec, 'kota' => self::kab($kab), 'provinsi' => $prov, 'negara' => 'Indonesia', 'kode_pos' => $pos, 'telp' => $telp, 'fax' => $fax,
        ];
        $pic = fn ($k) => ['nama' => $x->{"pic_{$k}_name"}, 'jabatan' => $x->{"pic_{$k}_position"}, 'hp' => $x->{"pic_{$k}_mobile"}, 'email' => $x->{"pic_{$k}_email"}];
        $berkas = fn ($f) => $f ?: null;

        return [
            'kode_pelanggan' => $x->kode_pelanggan, 'wilayah' => $x->wilayah, 'marketing' => $x->marketing,
            'perusahaan' => ['nama' => $x->nama_customer, 'induk' => $x->induk_perusahaan, 'tipe_bisnis' => (int) $x->tipe_bisnis === 10 ? $x->tipe_bisnis_lain : (self::TIPE_BISNIS[(int) $x->tipe_bisnis] ?? '-'), 'kepemilikan' => $x->ownership_label ?? '-'],
            'alamat_npwp' => $alamat($x->alamat_billing, $x->kelurahan_billing, $x->kecamatan_billing, $x->kabupaten_payment, $x->propinsi_payment, $x->postalcode_billing, $x->telp_billing, $x->fax_billing),
            'alamat_pusat' => $alamat($x->alamat_customer, $x->kelurahan_customer, $x->kecamatan_customer, $x->kabupaten_customer, $x->propinsi_customer, $x->postalcode_customer, $x->telp_customer, $x->fax_customer),
            'alamat_kirim' => array_values(array_filter(array_map('strval', array_slice($pd, 0, 3)))),
            'alamat_invoice' => $x->invoice_delivery_addr_primary,
            'pic_invoice' => ['nama' => $x->pic_invoice_name, 'hp' => $x->pic_invoice_mobile, 'jabatan' => $x->pic_invoice_position, 'email' => $x->pic_invoice_email],
            'dokumen' => [
                ['kunci' => 'sert', 'nama' => 'Certificate (Akta Pendirian)', 'nomor' => $legal[0]['nomor'] ?? $x->nomor_sertifikat, 'file' => $berkas($x->nomor_sertifikat_file)],
                ['kunci' => 'npwp', 'nama' => 'NPWP', 'nomor' => $legal[1]['nomor'] ?? $x->nomor_npwp, 'file' => $berkas($x->nomor_npwp_file)],
                ['kunci' => 'siup', 'nama' => 'SIUP', 'nomor' => $legal[2]['nomor'] ?? $x->nomor_siup, 'file' => $berkas($x->nomor_siup_file)],
                ['kunci' => 'tdp', 'nama' => 'TDP', 'nomor' => $legal[3]['nomor'] ?? $x->nomor_tdp, 'file' => $berkas($x->nomor_tdp_file)],
            ],
            'pic' => [
                'Director / Owner' => $pic('decision'), 'Procurement' => $pic('ordering'), 'Finance / Billing' => $pic('billing'), 'Fuelman' => $pic('fuelman'),
            ],
            'pembayaran' => [
                'metode_harga' => [1 => 'Discount Pricelist', 2 => 'Formula MOPS'][(int) $x->calculate_method] ?? '',
                'cara_bayar' => $metode[(int) $x->payment_method] ?? '',
                'top' => $x->jenis_payment === 'CREDIT' ? 'CREDIT '.$x->top_payment.' Days '.($x->jenis_net ? ($kond[(int) $x->jenis_net] ?? '') : '') : ['CBD' => 'CBD (Cash Before Delivery)', 'COD' => 'COD (Cash On Delivery)'][$x->jenis_payment] ?? '',
                'bank' => $x->bank_name, 'mata_uang' => $x->curency, 'alamat_bank' => $x->bank_address, 'rekening' => $x->account_number,
                'fasilitas_kredit' => (int) $x->credit_facility === 1 ? 'Yes' : 'No', 'kreditor' => $x->creditor,
            ],
            'pasokan' => [
                'skema' => (int) $x->supply_shceme === 1 ? 'Trucking' : 'SPOB / Vessel',
                'produk' => [1 => 'Prodiesel Bio (Bio Diesel)', 2 => 'Promarine (MFO)', 3 => 'Eneos (Lubricant)'][(int) $x->specify_product] ?? '',
                'volume_bulanan' => $x->volume_per_month, 'jam' => 'From '.$x->operational_hour_from.' To '.$x->operational_hour_to,
                'penyerahan' => (int) $x->nico === 1 ? 'Loco' : 'Delivered',
            ],
        ];
    }

    /** Tab "Review": Customer Review Form (bagian 1 s/d 5) seperti __get_review_customer.php. */
    private function review(object $x, $tipe, array $lampiran): array
    {
        $q = fn ($label, $v) => ['q' => $label, 'a' => $v];

        return [
            'customer' => $x->nama_customer, 'status_perusahaan' => $tipe ?: '-', 'tipe_perusahaan' => $x->ownership_label ?? '-',
            'alamat' => trim($x->alamat_customer.' '.self::kab($x->kabupaten_customer).' '.$x->propinsi_customer),
            'umum' => [
                $q('2.1 Sejak kapan perusahaan itu menjalankan bisnisnya?', $x->review2),
                $q('2.2 Jumlah cabang yang dimiliki (lokasi kabupaten / kota)', $x->review7),
                $q('Jumlah karyawan saat ini', $x->review5),
                $q('2.3 Berbisnis/kerjasama dengan siapa saja? (vendor / customer)', $x->review10),
                $q('2.4 Rata-rata hari penerimaan pembayaran dari pemberi kerja', $x->review16),
                $q('2.5 Jenis assets yang dimiliki (jenis, jumlah, status, bukti kepemilikan)', $x->jenis_asset),
                $q('2.6 Kelengkapan dokumen tagihan untuk proses pembayaran', $x->kelengkapan_dok_tagihan),
                $q('2.7 Alur proses pemeriksaan dokumen dan waktu sampai pembayaran', $x->alur_proses_periksaan),
                $q('2.8 Jadwal penerimaan invoice dan jadwal pembayaran tagihan', $x->jadwal_penerimaan),
                $q('2.9 Pemegang authority pembayaran (nama, posisi, no. HP)', $x->review8),
                $q('2.10 Existing fuel vendor (nama, credit term)', $x->review11),
                $q('2.11 Historical / background bisnis dengan PT. Pro Energi', $x->background_bisnis),
                $q('2.12 Lokasi depo sumber produk (terminal)', $x->lokasi_depo),
                $q('2.13 Opportunity business dengan perusahaan itu', $x->opportunity_bisnis),
            ],
            'kredit_diajukan' => (int) $x->credit_limit_diajukan, 'catatan_marketing' => $x->review_summary,
            'lama' => [
                $q('Jenis usaha customer', $x->review1),
                $q('Pemilik perusahaan', $x->review3),
                $q('Lokasi perusahaan: milik sendiri atau sewa', $x->review4),
                $q('Salary adjustment dan bonus tiap tahun', $x->review6),
                $q('Track record pembayaran atas supplier sebelumnya', $x->review12),
                $q('Alasan memilih Pro Energi', $x->review13),
                $q('Bank yang aktif digunakan', $x->review14),
                $q('Facility dari bank tersebut', $x->review15),
                $q('Potensi volume dalam 1 bulan (liter)', $x->review9),
            ],
            'lampiran' => $lampiran,
        ];
    }

    /** Berkas: jenis kyc ({ref}=urutan), review ({ref}=no_urut), legal ({ref}=sert|npwp|siup|tdp). Sama dengan download-file.php tipe 3, 2, dan folder images. */
    public function berkas(Request $r, int $id, string $jenis, string $ref)
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data verifikasi tidak ditemukan.'], 404);
        }
        $v = DB::table('pro_customer_verification')->where('id_verification', $id)->first();
        if ($jenis === 'kyc') {
            $k = array_values(json_decode((string) $v->finance_data_kyc, true) ?: [])[(int) $ref] ?? null;

            return LegacyFile::response($k ? LegacyFile::fromStored($k['filenya'] ?? '') : null, $k['file_upload_ori'] ?? 'kyc');
        }
        if ($jenis === 'review') {
            $rev = DB::table('pro_customer_review')->where('id_verification', $id)->value('id_review');
            $f = DB::table('pro_customer_review_attchment')->where('id_review', $rev)->where('id_verification', $id)->where('no_urut', (int) $ref)->first();

            return LegacyFile::response($f ? 'lampiran/RA_'.$rev.'_'.$f->no_urut.'_'.basename($f->review_attach_ori) : null, $f->review_attach_ori ?? 'lampiran');
        }
        $c = DB::table('pro_customer')->where('id_customer', $v->id_customer)->first();
        $peta = ['sert' => ['sert_file', $c->nomor_sertifikat_file], 'npwp' => ['npwp_file', $c->nomor_npwp_file], 'siup' => ['siup_file', $c->nomor_siup_file], 'tdp' => ['tdp_file', $c->nomor_tdp_file]];
        if ($jenis !== 'legal' || ! isset($peta[$ref]) || ! $peta[$ref][1]) {
            return response()->json(['message' => 'Berkas tidak ditemukan.', 'code' => 'FILE_MISSING'], 404);
        }

        return LegacyFile::response('images/'.$peta[$ref][0].$c->id_customer.'_'.basename($peta[$ref][1]), $peta[$ref][1]);
    }

    /** Keputusan BM/OM: result 1 setuju, 2 tolak; summary wajib. */
    public function decide(Request $r, int $id, AccurateClient $api): JsonResponse
    {
        if (! $this->find($r, $id)) {
            return response()->json(['message' => 'Data verifikasi tidak ditemukan.'], 404);
        }
        $d = $r->validate(['result' => 'required|in:1,2', 'summary' => 'required|string|max:5000']);
        $u = $r->user();
        $role = (int) $u->id_role;
        $col = $role === 7 ? 'sm' : 'om';
        $approval = (int) $d['result'];
        $summary = str_replace(["\r\n", "\r", "\n"], '<br />', self::esc($d['summary']));

        try {
            return DB::transaction(function () use ($id, $d, $api, $u, $role, $col, $approval, $summary) {
                $v = DB::table('pro_customer_verification')->where('id_verification', $id)->lockForUpdate()->first();
                $menunggu = $role === 7
                    ? ((int) $v->disposisi_result === 2 && (int) $v->sm_result === 0)
                    : ((int) $v->disposisi_result === 3 && (int) $v->om_result === 0);
                if (! $menunggu || (int) $v->is_approved !== 0) {
                    return response()->json(['message' => 'Data ini sudah diputuskan atau belum waktunya Anda memutuskan.', 'code' => 'STATE'], 409);
                }
                $idc = (int) $v->id_customer;
                $cust = DB::table('pro_customer')->where('id_customer', $idc)->first();

                $upd = ["{$col}_pic" => $u->fullname, "{$col}_summary" => $summary, "{$col}_result" => $approval, "{$col}_tgl_proses" => now(),
                    'is_approved' => $approval, 'role_approve' => $role, 'tanggal_approved' => now(), 'is_active' => 0];
                if ($approval === 1) {
                    if ($role === 7) {
                        $upd['disposisi_result'] = 3; // BM = keputusan akhir
                    }
                } else {
                    $upd['disposisi_result'] = 0;
                }
                DB::table('pro_customer_verification')->where('id_verification', $id)->update($upd);

                if ($approval === 1) {
                    DB::table('pro_customer')->where('id_customer', $idc)->update(['is_verified' => 1, 'status_customer' => 2, 'prospect_customer_date' => now()]);
                    if ($api->enabled()) {
                        $this->buatDiAccurate($api, $cust);
                    }
                } else {
                    $mkt = DB::table('acl_user')->where('id_user', $cust->id_marketing)->value('email_user');
                    if ($mkt) {
                        ApprovalMail::send([$mkt], 'Penolakan Data Customer '.date('d/m/Y'), e($u->fullname).' telah menolak verifikasi data customer '.e($cust->nama_customer));
                    }
                }

                return response()->json(['ok' => true, 'status' => $approval === 1 ? 'disetujui' : 'ditolak']);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'ACCURATE'], 502);
        }
    }

    /** customer/save.do, setara blok akhir evaluation-prospective.php. Gagal = RuntimeException (transaksi dibatalkan). */
    private function buatDiAccurate(AccurateClient $api, object $c): void
    {
        $row = DB::table('pro_customer as a')->join('pro_master_provinsi as b', 'a.prov_customer', '=', 'b.id_prov')
            ->join('pro_master_kabupaten as k', 'a.kab_customer', '=', 'k.id_kab')->join('pro_master_cabang as d', 'a.id_wilayah', '=', 'd.id_master')
            ->where('a.id_customer', $c->id_customer)->first(['a.*', 'b.nama_prov', 'k.nama_kab', 'd.nama_cabang']);

        $npwp = str_replace([',', '.', '-', ' '], '', (string) $row->nomor_npwp);
        if (strlen($npwp) === 15) {
            $npwp = '0'.$npwp;
        }
        $last = DB::table('pro_customer')->where('kode_pelanggan', 'like', 'TEMP%')->orderByRaw('CAST(SUBSTRING(kode_pelanggan, 5) AS UNSIGNED) DESC')->value('kode_pelanggan');
        $n = (int) substr((string) $last, 4) + 1;

        $ship = DB::table('pro_customer_lcr as a')->join('pro_master_provinsi as b', 'a.prov_survey', '=', 'b.id_prov')
            ->join('pro_master_kabupaten as k', 'a.kab_survey', '=', 'k.id_kab')->where('a.id_customer', $c->id_customer)
            ->get(['a.alamat_survey', 'b.nama_prov', 'k.nama_kab'])
            ->map(fn ($s) => ['street' => $s->alamat_survey, 'city' => $s->nama_kab, 'country' => 'Indonesia', 'province' => $s->nama_prov])->all();

        $res = $api->post('customer/save.do', [
            'name' => $row->nama_customer, 'customerNo' => 'TEMP'.$n, 'transDate' => now(config('accurate.timezone'))->format('d/m/Y'),
            'billCity' => $row->nama_kab, 'billCountry' => 'Indonesia', 'billProvince' => $row->nama_prov, 'billStreet' => $row->alamat_customer,
            'billZipCode' => $row->postalcode_customer, 'branchName' => $row->nama_cabang, 'npwpNo' => $npwp, 'workPhone' => $row->telp_customer,
            'email' => $row->email_customer, 'fax' => $row->fax_customer, 'customerLimitAge' => true, 'customerLimitAgeValue' => $row->top_payment,
            'customerLimitAmount' => true, 'customerLimitAmountValue' => $row->credit_limit, 'shipSameAsBill' => true, 'taxSameAsBill' => true,
            'termName' => 'net '.$row->top_payment, 'detailShipAddress' => $ship,
        ]);
        if (empty($res['s'])) {
            throw new \RuntimeException(($res['d'][0] ?? 'Gagal membuat customer di Accurate').' - Response dari Accurate');
        }
        DB::table('pro_customer')->where('id_customer', $c->id_customer)->update(['id_accurate' => $res['r']['id'] ?? null, 'kode_pelanggan' => 'TEMP'.$n]);
    }
}
