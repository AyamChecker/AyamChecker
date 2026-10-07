<?php
header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| AYAM CHECKER -> CEKBANK API
|--------------------------------------------------------------------------
| 1. Daftar / dapatkan API key dari CekBank.
| 2. Isi API key di bawah.
| 3. Upload index.html dan api.php ke folder yang sama.
|
| JANGAN memasukkan API key ke index.html.
|--------------------------------------------------------------------------
*/

$CEKBANK_API_KEY = 'ISI_API_KEY_CEKBANK_DI_SINI';
$CEKBANK_BASE = 'https://cekbank.web.id/api/v1';

function json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cekbank_request($method, $url, $apiKey, $body = null) {
    $ch = curl_init($url);

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError) {
        return [
            'ok' => false,
            'http' => 502,
            'data' => ['success' => false, 'error' => 'Server gagal terhubung ke CekBank.']
        ];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'http' => 502,
            'data' => ['success' => false, 'error' => 'CekBank mengirim response yang tidak valid.']
        ];
    }

    return [
        'ok' => ($httpCode >= 200 && $httpCode < 300),
        'http' => $httpCode,
        'data' => $data
    ];
}

if ($CEKBANK_API_KEY === 'ISI_API_KEY_CEKBANK_DI_SINI' || trim($CEKBANK_API_KEY) === '') {
    json_response([
        'success' => false,
        'error' => 'API key CekBank belum dipasang di api.php.'
    ], 500);
}

$action = $_POST['action'] ?? '';

/*
|--------------------------------------------------------------------------
| DAFTAR BANK
|--------------------------------------------------------------------------
*/
if ($action === 'banks') {
    $r = cekbank_request('GET', $CEKBANK_BASE . '/banks', $CEKBANK_API_KEY);

    if (!$r['ok']) {
        json_response([
            'success' => false,
            'error' => $r['data']['error'] ?? $r['data']['message'] ?? 'Gagal mengambil daftar bank dari CekBank.',
            'provider_http' => $r['http']
        ], $r['http'] >= 400 ? $r['http'] : 502);
    }

    json_response([
        'success' => true,
        'data' => $r['data']['data'] ?? []
    ]);
}

/*
|--------------------------------------------------------------------------
| VALIDASI BANK / E-WALLET
|--------------------------------------------------------------------------
*/
if ($action === 'bank' || $action === 'ewallet') {
    $accountNumber = preg_replace('/[^0-9]/', '', $_POST['rekening'] ?? $_POST['no'] ?? '');
    $provider = strtoupper(trim($_POST['bank'] ?? $_POST['ewallet'] ?? ''));

    if ($accountNumber === '' || strlen($accountNumber) < 5) {
        json_response([
            'success' => false,
            'error' => 'Nomor rekening / e-wallet tidak valid.'
        ], 400);
    }

    /*
     * Mapping nama dari HTML Ayam Checker ke kode CekBank.
     * Jika CekBank menggunakan kode berbeda untuk provider tertentu,
     * ubah nilainya di bagian ini.
     */
    $map = [
        'BANK_BCA'          => 'BCA',
        'BANK_BRI'          => 'BRI',
        'BANK_BNI'          => 'BNI',
        'BANK_MANDIRI'      => 'MANDIRI',
        'BANK_CIMB_NIAGA'   => 'CIMB',
        'BANK_PERMATA'      => 'PERMATA',
        'SEABANK_INDONESIA' => 'SEABANK',
        'BANK_JAGO'         => 'JAGO',

        'DANA'              => 'DANA',
        'OVO'               => 'OVO',
        'GOPAY'             => 'GOPAY',
        'SHOPEEPAY'         => 'SHOPEEPAY'
    ];

    $bankCode = $map[$provider] ?? $provider;

    $payload = [
        'bank_code' => $bankCode,
        'account_number' => $accountNumber
    ];

    $r = cekbank_request(
        'POST',
        $CEKBANK_BASE . '/verify',
        $CEKBANK_API_KEY,
        $payload
    );

    $d = $r['data'];

    if (!$r['ok']) {
        $message = $d['error'] ?? $d['message'] ?? 'Rekening tidak dapat diverifikasi.';

        json_response([
            'success' => false,
            'error' => $message,
            'provider_http' => $r['http'],
            'data' => $d['data'] ?? null
        ], $r['http'] >= 400 ? $r['http'] : 502);
    }

    $account = $d['data'] ?? [];

    /*
     * Normalisasi response agar index.html Anda
     * bisa langsung menampilkan nama.
     */
    json_response([
        'success' => (bool)($d['success'] ?? false),
        'data' => [
            'nama' => $account['account_name'] ?? null,
            'account_name' => $account['account_name'] ?? null,
            'name' => $account['account_name'] ?? null,
            'account_holder' => $account['account_name'] ?? null,
            'account_number' => $account['account_number'] ?? $accountNumber,
            'bank_code' => $account['bank_code'] ?? $bankCode,
            'status' => $d['status'] ?? null,
            'is_cached' => $d['is_cached'] ?? null,
            'response_time_ms' => $d['response_time_ms'] ?? null
        ]
    ]);
}

json_response([
    'success' => false,
    'error' => 'Action API tidak dikenali.'
], 400);
