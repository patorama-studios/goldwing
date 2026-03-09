<?php
$url = 'http://127.0.0.1:8001/apply.php';
$data = [
    'csrf_token' => 'dummy', // Validation bypassed for quick test or we fetch one
    'ajax' => '1',
    'first_name' => 'Test',
    'last_name' => 'Agent',
    'email' => 'test@example.com',
    'membership_full' => '1',
    'full_magazine_type' => 'DIGITAL',
    'full_period_key' => '1',
    'full_vehicle_payload' => '[{"make":"Honda","model":"Goldwing","year":"2020","rego":"ABC-123"}]',
    'associate_vehicle_payload' => '[]'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpcode\n";
echo "Response: $response\n";
