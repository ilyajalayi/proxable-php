<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_GET['link'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing 'link' parameter"]);
    exit;
}

$target_url = $_GET['link'];

// شبیه‌سازی getallheaders() برای هاست‌هایی که پشتیبانی نمی‌کنند
function get_request_headers() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) == 'HTTP_') {
            $header_name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$header_name] = $value;
        }
    }
    return $headers;
}

// cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $target_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // دنبال کردن ریدایرکت
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // در صورت مشکل SSL
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);          // زمان محدود برای پاسخ

// هدرها
$headers = [];
foreach (get_request_headers() as $key => $value) {
    if (strtolower($key) !== 'host') {
        $headers[] = "$key: $value";
    }
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(["error" => curl_error($ch)]);
    curl_close($ch);
    exit;
}

$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ارسال پاسخ
http_response_code($http_code);
header('Content-Type: application/json');
echo $response;
