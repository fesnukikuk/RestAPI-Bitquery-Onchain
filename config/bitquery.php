<?php

return [
    // Endpoint GraphQL Bitquery V2
    'endpoint' => env('BITQUERY_ENDPOINT', 'https://streaming.bitquery.io/graphql'),

    // Access token Bitquery (formatnya ory_at_...), dikirim sebagai "Authorization: Bearer ..."
    'token' => env('BITQUERY_TOKEN'),

    // Lama data disimpan di cache (detik). Cache dipakai bersama oleh SEMUA pengunjung,
    // jadi makin besar angkanya, makin hemat kuota Bitquery.
    'cache_ttl' => (int) env('BITQUERY_CACHE_TTL', 300),

    // Live Feed: tabel diperbarui tiap N detik. Hanya untuk pengguna yang sudah masuk dengan wallet,
    // karena memakai kuota Bitquery lebih banyak.
    'live_interval' => (int) env('BITQUERY_LIVE_INTERVAL', 30),

    // Jaringan EVM yang bisa dipilih. Kunci harus sama dengan nama network di Bitquery.
    'networks' => [
        'eth'      => ['name' => 'Ethereum', 'explorer' => 'https://etherscan.io'],
        'bsc'      => ['name' => 'BNB Chain', 'explorer' => 'https://bscscan.com'],
        'matic'    => ['name' => 'Polygon', 'explorer' => 'https://polygonscan.com'],
        'arbitrum' => ['name' => 'Arbitrum', 'explorer' => 'https://arbiscan.io'],
        'base'     => ['name' => 'Base', 'explorer' => 'https://basescan.org'],
        'optimism' => ['name' => 'Optimism', 'explorer' => 'https://optimistic.etherscan.io'],
    ],
];
