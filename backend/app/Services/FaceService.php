<?php

namespace App\Services;

use App\Models\FaceTemplate;
use InvalidArgumentException;

/** Validasi dan pencocokan embedding wajah (face-api, 128 dimensi). */
class FaceService
{
    /** @throws InvalidArgumentException bila bukan embedding yang masuk akal. */
    public function validate(mixed $d): array
    {
        $n = (int) config('orca.face.dimensions');

        if (! is_array($d) || count($d) !== $n || ! array_is_list($d)) {
            throw new InvalidArgumentException('Data wajah tidak valid.');
        }
        foreach ($d as $v) {
            if (! (is_int($v) || is_float($v)) || ! is_finite((float) $v) || abs($v) > 5) {
                throw new InvalidArgumentException('Data wajah tidak valid.');
            }
        }

        $d = array_map('floatval', $d);
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $d)));
        $mean = array_sum($d) / $n;
        $var  = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $d)) / $n;

        // Vektor nol / konstan = bukan wajah (mis. klien rusak atau percobaan palsu).
        if ($norm < 0.5 || $var < 1e-5) {
            throw new InvalidArgumentException('Data wajah tidak valid.');
        }

        return $d;
    }

    public function distance(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $i => $v) {
            $s += ($v - $b[$i]) ** 2;
        }

        return sqrt($s);
    }

    /** Jarak terkecil ke template milik user; null bila belum mendaftar. */
    public function bestDistance(int $idUser, array $descriptor): ?float
    {
        $best = null;
        foreach (FaceTemplate::where('id_user', $idUser)->get() as $t) {
            $tpl = $t->template;
            if (! is_array($tpl) || count($tpl) !== count($descriptor)) {
                continue;
            }
            $d = $this->distance($descriptor, $tpl);
            $best = $best === null ? $d : min($best, $d);
        }

        return $best;
    }

    public function matches(float $distance): bool
    {
        return $distance <= (float) config('orca.face.threshold');
    }
}
