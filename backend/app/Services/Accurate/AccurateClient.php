<?php

namespace App\Services\Accurate;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Klien Accurate Online. Setara dengan curl_get/curl_post/curl_delete/curl_multi_batch di curl.php web lama:
 * header X-Api-Timestamp (d/m/Y H:i:s), X-Api-Signature (Base64 dari HMAC-SHA256 raw atas timestamp) dan Authorization Bearer.
 * Hasil selalu array dengan 's' (bool) dan 'd' (daftar pesan), data sukses ada di 'r' / 'd' sesuai endpoint.
 */
class AccurateClient
{
    public function enabled(): bool
    {
        return (bool) config('accurate.enabled') && filled(config('accurate.token')) && filled(config('accurate.secret'));
    }

    public function signature(string $timestamp): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp, (string) config('accurate.secret'), true));
    }

    private function timestamp(): string
    {
        return now(config('accurate.timezone'))->format('d/m/Y H:i:s');
    }

    private function url(string $path): string
    {
        return rtrim((string) config('accurate.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function pending(): PendingRequest
    {
        $ts = $this->timestamp();

        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Api-Timestamp' => $ts,
            'X-Api-Signature' => $this->signature($ts),
            'Authorization' => 'Bearer '.config('accurate.token'),
        ])->timeout(config('accurate.timeout'))->connectTimeout(config('accurate.timeout'));
    }

    /** Menyeragamkan hasil: gagal koneksi, bukan JSON (token kedaluwarsa), atau HTTP >= 400 selalu menjadi ['s' => false, 'd' => [pesan]]. */
    public static function normalize(?string $body, int $status, string $connectionError = ''): array
    {
        if ($connectionError !== '') {
            return ['s' => false, 'd' => ['Curl error: '.$connectionError]];
        }
        $result = json_decode((string) $body, true);
        if (! is_array($result)) {
            $snippet = trim((string) $body);
            $snippet = $snippet === '' ? '(response kosong)' : substr($snippet, 0, 300);

            return ['s' => false, 'd' => ['HTTP '.$status.' - Response Accurate bukan JSON (kemungkinan token expired/tidak valid): '.$snippet]];
        }
        if (! array_key_exists('s', $result) && $status >= 400) {
            $result['s'] = false;
            $result['d'] = $result['d'] ?? ['HTTP '.$status.' dari Accurate'];
        }

        return $result;
    }

    private function run(string $method, string $path, ?array $data = null, array $query = []): array
    {
        try {
            $req = $this->pending();
            $url = $this->url($path);
            $res = match ($method) {
                'GET' => $req->get($url, $query),
                'POST' => $req->withBody(json_encode($data), 'application/json')->post($url),
                'DELETE' => $req->withBody(json_encode($data), 'application/json')->delete($url),
            };

            return self::normalize($res->body(), $res->status());
        } catch (ConnectionException $e) {
            return self::normalize(null, 0, $e->getMessage());
        }
    }

    public function get(string $path, array $query = []): array
    {
        return $this->run('GET', $path, null, $query);
    }

    public function post(string $path, array $data): array
    {
        return $this->run('POST', $path, $data);
    }

    public function delete(string $path, array $data): array
    {
        return $this->run('DELETE', $path, $data);
    }

    /**
     * Banyak request sekaligus, paling banyak $max paralel dan $max request per jendela waktu (batas Accurate per token).
     *
     * @param  array<string|int, array{method?:string, path:string, data?:array, query?:array}>  $requests
     * @return array<string|int, array>
     */
    public function batch(array $requests, ?int $max = null): array
    {
        $max = max(1, $max ?? (int) config('accurate.max_concurrent'));
        $window = (float) config('accurate.rate_window');
        $results = [];
        $lastStart = null;
        foreach (array_chunk($requests, $max, true) as $chunk) {
            if ($lastStart !== null && $window > 0) {
                $wait = $window - (microtime(true) - $lastStart);
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1_000_000));
                }
            }
            $lastStart = microtime(true);
            $responses = Http::pool(function (Pool $pool) use ($chunk) {
                $calls = [];
                foreach ($chunk as $key => $r) {
                    $ts = $this->timestamp();
                    $p = $pool->as((string) $key)->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Api-Timestamp' => $ts,
                        'X-Api-Signature' => $this->signature($ts),
                        'Authorization' => 'Bearer '.config('accurate.token'),
                    ])->timeout(config('accurate.timeout'))->connectTimeout(config('accurate.timeout'));
                    $url = $this->url($r['path']);
                    $calls[] = strtoupper($r['method'] ?? 'GET') === 'POST'
                        ? $p->withBody(json_encode($r['data'] ?? []), 'application/json')->post($url)
                        : $p->get($url, $r['query'] ?? []);
                }

                return $calls;
            });
            foreach ($chunk as $key => $_) {
                $res = $responses[(string) $key] ?? null;
                $results[$key] = $res instanceof \Illuminate\Http\Client\Response
                    ? self::normalize($res->body(), $res->status())
                    : self::normalize(null, 0, $res instanceof \Throwable ? $res->getMessage() : 'tidak ada respons');
            }
        }

        return $results;
    }
}
