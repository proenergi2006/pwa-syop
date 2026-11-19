<?php

namespace App\Support;

/** Pembuat arsip ZIP minimal (tanpa kompresi, metode "stored") untuk berkas kecil seperti .xlsx; tidak butuh ekstensi zip. */
class ZipStore
{
    /** @var array<string,string> nama => isi */
    private array $files = [];

    public function add(string $name, string $data): void
    {
        $this->files[$name] = $data;
    }

    public function build(): string
    {
        $t = getdate();
        $time = (($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2)) & 0xFFFF;
        $date = ((max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']) & 0xFFFF;
        $out = '';
        $central = '';
        $n = 0;
        foreach ($this->files as $name => $data) {
            $crc = crc32($data);
            $len = strlen($data);
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0).$name.$data; // 0x0800 = nama berkas UTF-8
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $n++;
        }

        return $out.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
    }
}
