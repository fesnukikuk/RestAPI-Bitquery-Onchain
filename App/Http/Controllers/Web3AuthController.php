<?php

namespace App\Http\Controllers;

use Elliptic\EC;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use kornrunner\Keccak;
use Throwable;

/**
 * Masuk dengan wallet (gaya Sign-In with Ethereum, EIP-4361).
 *
 * Alur: server membuat pesan sekali pakai -> wallet menandatangani (gratis, tanpa transaksi)
 * -> server memulihkan alamat dari tanda tangan dan mencocokkannya dengan alamat yang diklaim.
 * Private key tidak pernah meninggalkan wallet.
 */
class Web3AuthController extends Controller
{
    private const EXPIRES_MINUTES = 5;

    /** Langkah 1: buat pesan yang harus ditandatangani. */
    public function nonce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'chainId' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ]);

        $address = strtolower($data['address']);
        $issuedAt = now()->utc();

        $message = implode("\n", [
            $request->getHttpHost().' wants you to sign in with your Ethereum account:',
            $this->checksum($address),
            '',
            'Masuk ke Bitquery Explorer. Tanda tangan ini gratis dan tidak mengirim transaksi apa pun.',
            '',
            'URI: '.$request->getSchemeAndHttpHost(),
            'Version: 1',
            'Chain ID: '.($data['chainId'] ?? 1),
            'Nonce: '.Str::random(24),
            'Issued At: '.$issuedAt->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        // Pesan disimpan di server. Yang diverifikasi nanti adalah pesan ini, bukan kiriman browser.
        $request->session()->put('web3_login', [
            'address' => $address,
            'message' => $message,
            'expires' => $issuedAt->copy()->addMinutes(self::EXPIRES_MINUTES)->timestamp,
        ]);

        return response()->json(['message' => $message]);
    }

    /** Langkah 2: cek tanda tangan lalu buka sesi. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address'   => ['required', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'regex:/^0x[a-fA-F0-9]{130}$/'],
        ]);

        $login = $request->session()->pull('web3_login'); // sekali pakai

        if (! $login || $login['expires'] < now()->timestamp || strtolower($data['address']) !== $login['address']) {
            return response()->json(['message' => 'Sesi masuk sudah kedaluwarsa. Klik Hubungkan wallet lagi.'], 422);
        }

        if (! $this->signatureMatches($login['message'], $data['signature'], $login['address'])) {
            return response()->json(['message' => 'Tanda tangan tidak cocok dengan alamat wallet.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put('web3_address', $login['address']);

        return response()->json(['ok' => true, 'address' => $login['address']]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** Pulihkan alamat penanda tangan dari tanda tangan personal_sign, lalu bandingkan. */
    private function signatureMatches(string $message, string $signature, string $address): bool
    {
        try {
            $hash = Keccak::hash("\x19Ethereum Signed Message:\n".strlen($message).$message, 256);

            $r = substr($signature, 2, 64);
            $s = substr($signature, 66, 64);
            $v = hexdec(substr($signature, 130, 2));
            $recovery = $v >= 27 ? $v - 27 : $v;

            if ($recovery !== 0 && $recovery !== 1) {
                return false;
            }

            $publicKey = (new EC('secp256k1'))->recoverPubKey($hash, ['r' => $r, 's' => $s], $recovery);
            $derived = '0x'.substr(Keccak::hash(substr(hex2bin($publicKey->encode('hex')), 1), 256), 24);

            return hash_equals(strtolower($address), strtolower($derived));
        } catch (Throwable) {
            return false;
        }
    }

    /** Alamat dengan huruf besar-kecil sesuai EIP-55 (dibutuhkan format pesan EIP-4361). */
    private function checksum(string $address): string
    {
        $plain = substr(strtolower($address), 2);
        $hash = Keccak::hash($plain, 256);
        $out = '0x';

        foreach (str_split($plain) as $i => $char) {
            $out .= ctype_alpha($char) && hexdec($hash[$i]) >= 8 ? strtoupper($char) : $char;
        }

        return $out;
    }
}
