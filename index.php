<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ======= Utility functions =======
function send_json($data, $code=200){
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_request_data() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if(!$data) send_json(["error"=>"Invalid JSON input"], 400);
    return $data;
}

function multi_curl_requests($requests) {
    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach($requests as $id => $req) {
        $ch = curl_init();
        $url = $req['url'];

        // Apply automatic weight/rate if not manually set
        if(!isset($req['weight'])) $req['weight'] = 1; 
        if(!isset($req['rate'])) $req['rate'] = 1000; // dummy max rate, can be tuned

        $headers = isset($req['headers']) ? $req['headers'] : [];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_ENCODING, ""); // gzip support
        if($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_multi_add_handle($mh, $ch);
        $handles[$id] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach($handles as $id => $ch) {
        $content = curl_multi_getcontent($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if(curl_errno($ch)) {
            $results[$id] = [
                "endpoint"=>$requests[$id]['endpoint'],
                "response"=>["error"=>curl_error($ch)]
            ];
        } elseif ($http_code != 200) {
            $results[$id] = [
                "endpoint"=>$requests[$id]['endpoint'],
                "response"=>["error"=>"HTTP code $http_code", "body"=>$content]
            ];
        } else {
            $decoded = json_decode($content, true);
            if($decoded === null) {
                $results[$id] = [
                    "endpoint"=>$requests[$id]['endpoint'],
                    "response"=>["error"=>"Non-JSON response","body"=>$content]
                ];
            } else {
                $results[$id] = [
                    "endpoint"=>$requests[$id]['endpoint'],
                    "response"=>$decoded
                ];
            }
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ======= Default Binance endpoints =======
$default_endpoints = [
    "klines"=>"/api/v3/klines",
    "ticker_price"=>"/api/v3/ticker/price",
    "ticker_book"=>"/api/v3/ticker/bookTicker",
    "depth"=>"/api/v3/depth",
    "agg_trades"=>"/api/v3/aggTrades",
    "avg_price"=>"/api/v3/avgPrice",
    "exchange_info"=>"/api/v3/exchangeInfo",
    "server_time"=>"/api/v3/time"
];

// ======= Base endpoint (from client) =======
$data = get_request_data();
if(!isset($data['base_endpoint'])) send_json(["error"=>"Missing 'base_endpoint' parameter"],400);
$base = rtrim($data['base_endpoint'], "/");

// ======= Build requests =======
if(!isset($data['requests']) || !is_array($data['requests'])) send_json(["error"=>"Missing or invalid 'requests' array"],400);

$requests_to_send = [];
foreach($data['requests'] as $idx => $req) {
    $endpoint_name = $req['endpoint'] ?? null; // default endpoint
    $custom_endpoint = $req['custom_endpoint'] ?? null; // custom URL path
    $params = $req['params'] ?? [];
    $weight = $req['weight'] ?? null; // optional manual weight
    $rate = $req['rate'] ?? null; // optional manual rate

    if($custom_endpoint){
        $url = $base . "/" . ltrim($custom_endpoint,"/");
        $endpoint_key = "custom_$idx";
    } elseif ($endpoint_name && isset($default_endpoints[$endpoint_name])) {
        $url = $base . $default_endpoints[$endpoint_name] . "?" . http_build_query($params);
        $endpoint_key = $endpoint_name . "_$idx";
    } else {
        $requests_to_send[$idx] = [
            "endpoint"=>"unknown",
            "url"=>"",
            "response"=>["error"=>"Unknown endpoint or missing required params"]
        ];
        continue;
    }

    $requests_to_send[$idx] = [
        "endpoint"=>$endpoint_key,
        "url"=>$url,
        "weight"=>$weight, // if null, multi_curl_requests will assign auto
        "rate"=>$rate      // if null, multi_curl_requests will assign auto
    ];
}

// ======= Execute all requests =======
$results = multi_curl_requests($requests_to_send);

// ======= Return JSON =======
send_json(["results"=>$results]);
