<?php

namespace App\Support;

/**
 * Penulis .xlsx sederhana tanpa dependensi (satu lembar, teks inline, angka, beberapa gaya, sel gabungan, panel beku).
 * Berkas .xlsx = arsip ZIP berisi XML; ZIP ditulis sendiri (metode "stored") karena ekstensi zip PHP tidak ada di image Docker.
 *
 * Gaya (indeks cellXfs): 0 biasa, 1 judul, 2 header, 3 label, 4 angka, 5 seksi, 6 label total, 7 angka total, 8 header akhir pekan.
 */
class Xlsx
{
    public const S_NORMAL = 0;
    public const S_TITLE = 1;
    public const S_HEAD = 2;
    public const S_LABEL = 3;
    public const S_NUM = 4;
    public const S_SECTION = 5;
    public const S_TOTAL_LABEL = 6;
    public const S_TOTAL_NUM = 7;
    public const S_HEAD_OFF = 8;

    /** @var array<int, array<int, array{0:mixed,1:int}>> */
    private array $rows = [];
    private array $merges = [];
    private array $widths = [];
    private ?array $freeze = null;

    public function __construct(private string $sheet = 'Sheet1')
    {
    }

    /** Tambah satu baris. Sel = nilai biasa (teks -> gaya label, angka -> gaya angka) atau [nilai, gaya]. @return int nomor baris (mulai 1) */
    public function row(array $cells): int
    {
        $row = [];
        foreach ($cells as $c) {
            $row[] = is_array($c) ? [$c[0], (int) ($c[1] ?? self::S_NORMAL)] : [$c, is_int($c) || is_float($c) ? self::S_NUM : self::S_LABEL];
        }
        $this->rows[] = $row;

        return count($this->rows);
    }

    public function merge(string $ref): void
    {
        $this->merges[] = $ref;
    }

    public function width(int $kolom, float $lebar): void
    {
        $this->widths[$kolom] = $lebar;
    }

    /** Bekukan baris di atas $ySplit dan kolom di kiri $xSplit. */
    public function freeze(int $xSplit, int $ySplit): void
    {
        $this->freeze = [$xSplit, $ySplit];
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sheetXml(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($this->freeze) {
            [$fx, $fy] = $this->freeze;
            $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="1"><pane xSplit="'.$fx.'" ySplit="'.$fy.'" topLeftCell="'.self::col($fx).($fy + 1).'" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        }
        if ($this->widths) {
            $x .= '<cols>';
            foreach ($this->widths as $i => $w) {
                $x .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($this->rows as $r => $cells) {
            $x .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => [$v, $s]) {
                $ref = self::col($c).($r + 1);
                if ($v === null || $v === '') {
                    $x .= $s ? '<c r="'.$ref.'" s="'.$s.'"/>' : '';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="'.$ref.'" s="'.$s.'"><v>'.(is_float($v) ? rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') : $v).'</v></c>';
                } else {
                    $x .= '<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($this->merges) {
            $x .= '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $m) {
                $x .= '<mergeCell ref="'.$m.'"/>';
            }
            $x .= '</mergeCells>';
        }

        return $x.'</worksheet>';
    }

    private static function stylesXml(): string
    {
        $b = '<border><left style="thin"><color rgb="FFD0D5DD"/></left><right style="thin"><color rgb="FFD0D5DD"/></right><top style="thin"><color rgb="FFD0D5DD"/></top><bottom style="thin"><color rgb="FFD0D5DD"/></bottom><diagonal/></border>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.0"/></numFmts>'
            .'<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            .'<fills count="6"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCE8FA"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF1F6"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFFF3C4"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFBE3E3"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.$b.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** Isi berkas .xlsx. */
    public function build(): string
    {
        $zip = new ZipStore;
        $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->add('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.self::esc(mb_substr($this->sheet, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->add('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->add('xl/styles.xml', self::stylesXml());
        $zip->add('xl/worksheets/sheet1.xml', $this->sheetXml());

        return $zip->build();
    }
}
