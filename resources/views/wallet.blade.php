@extends('layouts.app')

@section('title', 'Cek wallet')
@section('heading', 'Cek transfer wallet')
@section('lead', 'Lihat token yang baru masuk dan keluar dari sebuah alamat wallet di '.$networkName.'.')

@section('filters')
    <form method="GET" class="row g-2 mb-1" data-loading>
        <div class="col-12 col-md">
            <label for="address" class="form-label small text-secondary mb-1">Alamat wallet</label>
            <input type="text" name="address" id="address" value="{{ $address }}" class="form-control mono"
                   placeholder="0x..." autocomplete="off" required>
        </div>
        <div class="col-auto">
            <label for="network" class="form-label small text-secondary mb-1">Jaringan blockchain</label>
            @include('partials.network')
        </div>
        <div class="col-auto d-flex align-items-end"><button class="btn btn-accent">Cari transfer</button></div>
    </form>
    <p class="small mb-3">
        @if (session('web3_address'))
            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-fill="#address" data-value="{{ session('web3_address') }}">Isi dengan wallet saya</button>
            <span class="text-secondary">atau</span>
        @else
            Belum punya alamat?
        @endif
        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-fill="#address" data-value="0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045">Isi dengan contoh alamat publik</button>
    </p>

    <details class="panel help p-3 mb-3">
        <summary>Apa arti halaman ini?</summary>
        <ul class="small mt-2 mb-0">
            <li><strong>Wallet</strong> adalah dompet kripto. Alamatnya berupa kode yang diawali 0x. Semua transfer di blockchain bersifat publik.</li>
            <li><strong>Masuk</strong> berarti wallet menerima token, <strong>Keluar</strong> berarti wallet mengirim token.</li>
            <li><strong>Lawan transaksi</strong> adalah alamat pengirim atau penerima di seberangnya.</li>
            <li>Yang tampil hanya transfer terbaru, bukan seluruh riwayat wallet.</li>
        </ul>
    </details>
@endsection

@section('content')
    @if (!empty($result['summary']))
        @include('partials.cards', ['cards' => [
            ['label' => 'Transfer ditemukan', 'value' => $result['summary']['count'], 'hint' => 'yang paling baru'],
            ['label' => 'Masuk', 'value' => $result['summary']['in'], 'hint' => 'transfer diterima wallet'],
            ['label' => 'Keluar', 'value' => $result['summary']['out'], 'hint' => 'transfer dikirim wallet'],
        ]])
    @endif

    @if (!empty($result['rows']))
        <input type="search" class="form-control mb-2" placeholder="Cari di tabel, misalnya nama token" aria-label="Cari di tabel" data-filter="#tabel">
    @endif

    <div class="panel table-responsive">
        <table class="table table-hover align-middle mb-0" id="tabel">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Arah</th>
                    <th>Jumlah</th>
                    <th>Lawan transaksi</th>
                    <th>Kode transaksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($result['rows'] as $r)
                    <tr>
                        <td class="text-nowrap"><time datetime="{{ $r['iso'] }}" data-local="datetime">{{ $r['time'] }} UTC</time></td>
                        <td class="{{ $r['outgoing'] ? 'out' : 'in' }}">{{ $r['direction'] }}</td>
                        <td>{{ $r['amount'] }}</td>
                        <td class="text-nowrap">@include('partials.addr', ['value' => $r['counterparty'], 'type' => 'address'])</td>
                        <td class="text-nowrap">@include('partials.addr', ['value' => $r['tx'], 'type' => 'tx'])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-secondary py-4">
                        {{ $address === '' ? 'Masukkan alamat wallet di atas, lalu klik Cari transfer.' : 'Tidak ada transfer terbaru untuk alamat ini di jaringan yang dipilih. Coba jaringan lain.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
