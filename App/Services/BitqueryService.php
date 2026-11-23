<?php

namespace App\Services;

use App\Exceptions\BitqueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BitqueryService
{
    /** DEX trade terbaru di satu jaringan EVM. */
    public function latestTrades(string $network, int $limit = 25, ?int $ttl = null): array
    {
        $network = $this->network($network);
        $limit = max(1, min($limit, 50));

        $res = $this->query(<<<GQL
        {
          EVM(network: {$network}) {
            DEXTrades(limit: {count: {$limit}}, orderBy: {descending: Block_Time}) {
              Block { Time }
              Transaction { Hash }
              Trade {
                Dex { ProtocolName }
                Buy { Amount AmountInUSD Currency { Symbol } }
                Sell { Amount Currency { Symbol } }
              }
            }
          }
        }
        GQL, $ttl);

        $rows = collect(data_get($res['data'], 'EVM.DEXTrades', []))->map(fn ($t) => [
            'time'    => $this->time(data_get($t, 'Block.Time')),
            'iso'     => $this->iso(data_get($t, 'Block.Time')),
            'dex'     => data_get($t, 'Trade.Dex.ProtocolName') ?: 'Tidak diketahui',
            'buy'     => $this->amount(data_get($t, 'Trade.Buy.Amount'), data_get($t, 'Trade.Buy.Currency.Symbol')),
            'sell'    => $this->amount(data_get($t, 'Trade.Sell.Amount'), data_get($t, 'Trade.Sell.Currency.Symbol')),
            'usd'     => $this->fmt(data_get($t, 'Trade.Buy.AmountInUSD'), 2),
            'usd_raw' => (float) data_get($t, 'Trade.Buy.AmountInUSD'),
            'tx'      => (string) data_get($t, 'Transaction.Hash'),
        ]);

        $byDex = $rows->groupBy('dex')->map->count()->sortDesc();

        return $this->result($res, $rows->all(), [
            'summary' => [
                'count'   => $rows->count(),
                'usd'     => $this->fmt($rows->sum('usd_raw'), 0),
                'top_dex' => $byDex->keys()->first() ?? '-',
            ],
            'chart' => ['labels' => $byDex->keys()->values()->all(), 'values' => $byDex->values()->all()],
        ]);
    }

    /** Token dengan volume trading (USD) terbesar dalam N jam terakhir. */
    public function topTokens(string $network, int $hours = 24, int $limit = 10): array
    {
        $network = $this->network($network);
        $limit = max(1, min($limit, 25));
        $since = now()->utc()->subHours($hours)->startOfMinute()->format('Y-m-d\TH:i:s\Z');

        $res = $this->query(<<<GQL
        {
          EVM(network: {$network}) {
            DEXTradeByTokens(
              orderBy: {descendingByField: "volume_usd"}
              limit: {count: {$limit}}
              where: {Block: {Time: {since: "{$since}"}}}
            ) {
              Trade { Currency { Symbol Name SmartContract } }
              volume_usd: sum(of: Trade_Side_AmountInUSD)
              trades: count
            }
          }
        }
        GQL);

        $rows = collect(data_get($res['data'], 'EVM.DEXTradeByTokens', []))->map(fn ($r) => [
            'symbol'     => data_get($r, 'Trade.Currency.Symbol') ?: '?',
            'name'       => data_get($r, 'Trade.Currency.Name') ?: '',
            'contract'   => (string) data_get($r, 'Trade.Currency.SmartContract'),
            'volume'     => $this->fmt(data_get($r, 'volume_usd'), 0),
            'volume_raw' => (float) data_get($r, 'volume_usd'),
            'trades'     => number_format((int) data_get($r, 'trades')),
            'trades_raw' => (int) data_get($r, 'trades'),
        ]);

        return $this->result($res, $rows->all(), [
            'summary' => [
                'top'    => data_get($rows->first(), 'symbol', '-'),
                'volume' => $this->fmt($rows->sum('volume_raw'), 0),
                'trades' => number_format($rows->sum('trades_raw')),
            ],
            'chart' => [
                'labels' => $rows->pluck('symbol')->all(),
                'values' => $rows->pluck('volume_raw')->map(fn ($v) => round($v))->all(),
            ],
        ]);
    }

    /** Transfer token masuk dan keluar untuk satu alamat wallet. */
    public function walletTransfers(string $network, string $address, int $limit = 25): array
    {
        $network = $this->network($network);
        $limit = max(1, min($limit, 50));
        $address = strtolower($address); // sudah divalidasi di controller (0x + 40 hex)

        $res = $this->query(<<<GQL
        {
          EVM(network: {$network}) {
            Transfers(
              limit: {count: {$limit}}
              orderBy: {descending: Block_Time}
              where: {any: [
                {Transfer: {Sender: {is: "{$address}"}}},
                {Transfer: {Receiver: {is: "{$address}"}}}
              ]}
            ) {
              Block { Time }
              Transaction { Hash }
              Transfer { Amount Sender Receiver Currency { Symbol } }
            }
          }
        }
        GQL);

        $rows = collect(data_get($res['data'], 'EVM.Transfers', []))->map(function ($t) use ($address) {
            $sender = strtolower((string) data_get($t, 'Transfer.Sender'));
            $outgoing = $sender === $address;

            return [
                'time'         => $this->time(data_get($t, 'Block.Time')),
                'iso'          => $this->iso(data_get($t, 'Block.Time')),
                'direction'    => $outgoing ? 'Keluar' : 'Masuk',
                'outgoing'     => $outgoing,
                'amount'       => $this->amount(data_get($t, 'Transfer.Amount'), data_get($t, 'Transfer.Currency.Symbol')),
                'counterparty' => (string) ($outgoing ? data_get($t, 'Transfer.Receiver') : data_get($t, 'Transfer.Sender')),
                'tx'           => (string) data_get($t, 'Transaction.Hash'),
            ];
        });

        return $this->result($res, $rows->all(), [
            'summary' => [
                'count' => $rows->count(),
                'in'    => $rows->where('outgoing', false)->count(),
                'out'   => $rows->where('outgoing', true)->count(),
            ],
        ]);
    }

    /** Gabungkan baris data dengan info kesegaran data (kapan diambil, apakah data lama). */
    private function result(array $res, array $rows, array $extra = []): array
    {
        return ['rows' => $rows, 'fetched_at' => $res['fetched_at'], 'stale' => $res['stale']] + $extra;
    }

    /**
     * Ambil data dari cache bersama; kalau belum ada, tanya Bitquery.
     * Kalau Bitquery gagal (kuota habis, gangguan), pakai salinan terakhir yang pernah berhasil.
     */
    private function query(string $graphql, ?int $seconds = null): array
    {
        $token = config('bitquery.token');

        if (blank($token)) {
            throw new BitqueryException('Aplikasi belum dikonfigurasi: BITQUERY_TOKEN belum diisi di file .env.');
        }

        $seconds ??= (int) config('bitquery.cache_ttl');
        $key = 'bitquery:v2:'.md5($graphql).':'.$seconds;
        $ttl = now()->addSeconds($seconds);

        if ($hit = Cache::get($key)) {
            return $hit + ['stale' => false];
        }

        try {
            // Kunci mencegah banyak pengunjung memanggil Bitquery bersamaan untuk data yang sama.
            return Cache::lock($key.':lock', 20)->block(10, function () use ($key, $ttl, $graphql, $token) {
                if ($hit = Cache::get($key)) {
                    return $hit + ['stale' => false];
                }

                $payload = ['data' => $this->fetch($graphql, $token), 'fetched_at' => time()];

                Cache::put($key, $payload, $ttl);
                Cache::put($key.':last', $payload, now()->addDay());

                return $payload + ['stale' => false];
            });
        } catch (Throwable $e) {
            if ($last = Cache::get($key.':last')) {
                Log::warning('Bitquery gagal, memakai data tersimpan: '.$e->getMessage());

                return $last + ['stale' => true];
            }

            throw $e;
        }
    }

    private function fetch(string $graphql, string $token): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(30)
            ->post(config('bitquery.endpoint'), ['query' => $graphql]);

        if ($response->failed()) {
            $body = Str::limit($response->body(), 500);
            Log::warning('Bitquery HTTP '.$response->status().': '.$body);

            throw new BitqueryException(
                $this->friendlyHttp($response->status(), $body),
                'HTTP '.$response->status().': '.$body
            );
        }

        $json = $response->json();

        if (! empty($json['errors'])) {
            $detail = collect($json['errors'])->pluck('message')->implode('; ');
            Log::warning('Bitquery GraphQL error: '.$detail);

            throw new BitqueryException($this->friendlyGraphql($detail), $detail);
        }

        return $json['data'] ?? [];
    }

    private function friendlyHttp(int $status, string $body): string
    {
        if (in_array($status, [401, 403], true)) {
            return 'Akses ke Bitquery ditolak. Token API tidak valid atau sudah kedaluwarsa. Buat token baru di akun Bitquery lalu isi ulang di file .env.';
        }

        if (in_array($status, [402, 429], true) || preg_match('/points|limit/i', $body)) {
            return 'Kuota data Bitquery sedang habis. Coba lagi beberapa saat lagi.';
        }

        return "Bitquery sedang bermasalah (kode {$status}). Coba lagi beberapa saat lagi.";
    }

    private function friendlyGraphql(string $detail): string
    {
        return preg_match('/points|limit|quota/i', $detail)
            ? 'Kuota data Bitquery sedang habis. Coba lagi beberapa saat lagi.'
            : 'Bitquery tidak bisa memproses permintaan data ini. Coba pilih jaringan atau pengaturan lain.';
    }

    private function network(string $network): string
    {
        return array_key_exists($network, config('bitquery.networks')) ? $network : 'eth';
    }

    private function time(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->utc()->format('d M Y H:i:s') : '-';
    }

    private function iso(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->utc()->toIso8601String() : '';
    }

    private function amount(mixed $value, ?string $symbol): string
    {
        return $this->fmt($value, 6).' '.(filled($symbol) ? $symbol : 'token');
    }

    private function fmt(mixed $value, int $decimals): string
    {
        $text = number_format((float) $value, $decimals, '.', ',');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
