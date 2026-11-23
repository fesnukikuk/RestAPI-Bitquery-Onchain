<?php

namespace App\Http\Controllers;

use App\Exceptions\BitqueryException;
use App\Services\BitqueryService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class BitqueryController extends Controller
{
    public function __construct(private BitqueryService $bitquery)
    {
    }

    /** Halaman 1: transaksi DEX terbaru. */
    public function trades(Request $request)
    {
        $network = $this->network($request);
        [$result, $error] = $this->safely(fn () => $this->bitquery->latestTrades($network));

        return view('trades', $this->viewData($network, $result, $error));
    }

    /** Data untuk Live Feed. Hanya bisa diakses setelah masuk dengan wallet (lihat routes/web.php). */
    public function feed(Request $request): JsonResponse
    {
        $network = $this->network($request);
        $interval = (int) config('bitquery.live_interval');
        [$result, $error] = $this->safely(fn () => $this->bitquery->latestTrades($network, 25, $interval));

        if ($error) {
            return response()->json(['message' => $error], 503);
        }

        return response()->json([
            'html' => view('partials.trade-rows', [
                'rows'     => $result['rows'],
                'explorer' => config("bitquery.networks.$network.explorer"),
            ])->render(),
            'fetched_at' => Carbon::createFromTimestamp($result['fetched_at'])->toIso8601String(),
            'stale'      => $result['stale'],
        ]);
    }

    /** Halaman 2: token dengan volume terbesar. */
    public function tokens(Request $request)
    {
        $network = $this->network($request);
        $hours = in_array((int) $request->query('hours'), [1, 6, 24], true) ? (int) $request->query('hours') : 24;
        [$result, $error] = $this->safely(fn () => $this->bitquery->topTokens($network, $hours));

        return view('tokens', $this->viewData($network, $result, $error) + ['hours' => $hours]);
    }

    /** Halaman 3: transfer masuk dan keluar sebuah wallet. */
    public function wallet(Request $request)
    {
        $network = $this->network($request);
        $address = trim((string) $request->query('address', ''));
        $result = $this->emptyResult();
        $error = null;

        if ($address !== '') {
            if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
                $error = 'Alamat tidak valid. Alamat wallet diawali 0x dan diikuti 40 karakter angka atau huruf a sampai f. Salin ulang dari dompet atau penjelajah blockchain.';
            } else {
                [$result, $error] = $this->safely(fn () => $this->bitquery->walletTransfers($network, $address));
            }
        }

        return view('wallet', $this->viewData($network, $result, $error) + ['address' => $address]);
    }

    private function viewData(string $network, array $result, ?string $error): array
    {
        return [
            'network'     => $network,
            'networkName' => config("bitquery.networks.$network.name"),
            'explorer'    => config("bitquery.networks.$network.explorer"),
            'result'      => $result,
            'error'       => $error,
        ];
    }

    private function network(Request $request): string
    {
        $network = (string) $request->query('network', 'eth');

        return array_key_exists($network, config('bitquery.networks')) ? $network : 'eth';
    }

    private function emptyResult(): array
    {
        return ['rows' => [], 'summary' => [], 'chart' => null, 'fetched_at' => null, 'stale' => false];
    }

    /** Jalankan pemanggilan API. Kalau gagal, kembalikan pesan yang ramah untuk pengunjung. */
    private function safely(Closure $callback): array
    {
        try {
            return [$callback(), null];
        } catch (BitqueryException $e) {
            $message = $e->getMessage();

            if (config('app.debug') && $e->detail) {
                $message .= ' (Detail: '.$e->detail.')';
            }

            return [$this->emptyResult(), $message];
        } catch (Throwable $e) {
            report($e);

            $message = 'Data belum bisa diambil saat ini. Periksa koneksi internet, lalu coba lagi beberapa saat lagi.';

            if (config('app.debug')) {
                $message .= ' (Detail: '.$e->getMessage().')';
            }

            return [$this->emptyResult(), $message];
        }
    }
}
