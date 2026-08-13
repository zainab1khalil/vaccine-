<?php

// Real WhatsApp API test for employee 2812 with early leave violation (Function 15)
$apiUrl = 'https://graph.facebook.com/v18.0';
$apiKey = 'EAAV7hIaXpDsBSMzvQDyfzT5fckaA3c3QeHrsxqWKmhqMtgHZBJyySWzWSgSsbalyxqFRXaSuJ8ri8MY3Q91JvvjMuIHzX91ZAkU2ZANgygRcTipUCBAl6uz5mCvhKaQjZAIZB99RWwZAc5wPJFwUKJh2a9ALnYa9VDvgUvcZCZB2iKF1J149vPLoZAqiaYbbMu5vVTS44mAZCbB69Qcpfh0tiAkmlOKiN2zlZCZAFWXhfaQGYZCMLDxGSMD038LQZA016qYUcQKneIw0STPmLugGqNknbN';
$senderNumber = '1163829286823941';

// Employee 2812 data
$employee = [
    'employee_id' => '2812',
    'name' => 'محمد أحمد',
    'phone_number' => '07751929934',
];

// Format phone number
$phone = preg_replace('/[^0-9]/', '', $employee['phone_number']);
if (substr($phone, 0, 2) === '07') {
    $phone = substr($phone, 1);
}
if (substr($phone, 0, 1) === '7' && (strlen($phone) === 10 || strlen($phone) === 11)) {
    $phone = '964' . $phone;
}
if (substr($phone, 0, 1) !== '+') {
    $phone = '+' . $phone;
}

echo "=== Function 15 Real WhatsApp Test: Employee 2812 ===\n\n";
echo "Employee: {$employee['name']} (ID: {$employee['employee_id']})\n";
echo "Original Phone: {$employee['phone_number']}\n";
echo "Formatted Phone: {$phone}\n\n";

// Build the Arabic message (Function 15 format with early leave)
$violationDate = '2026/08/11';
$articleNumber = 'المادة الأولى';
$violationType = 'مغادرة مبكرة لغاية 15 دقيقة';

$message = <<<MESSAGE
مرحباً {$employee['name']}،
نود إعلامكم بأنه تم تسجيل مخالفة وظيفية بحقكم وفقاً لسجلات الحضور واللوائح المعتمدة لدى المؤسسة.
تفاصيل المخالفة:
	• الرقم الوظيفي: {$employee['employee_id']}
	• نوع المخالفة: {$violationType}
	• تاريخ المخالفة: {$violationDate}
	• تفاصيل المخالفة: مغادرة مبكرة 15 دقيقة - اختبار النظام
	• المادة: {$articleNumber}
الجزاء المترتب:
تنبيه شفوي
تم تسجيل المخالفة والجزاء في نظام الموارد البشرية وفقاً للائحة المخالفات والجزاءات المعتمدة.
في حال كان لديكم اعتراض أو ملاحظات على المخالفة، يرجى اتباع إجراءات الاعتراض المعتمدة لدى إدارة الموارد البشرية.
مع كامل الاحترام،
فريق الموارد البشرية
MESSAGE;

echo "=== WhatsApp Message (Function 15 Format) ===\n\n";
echo $message . "\n\n";

// Prepare API request
$payload = [
    'messaging_product' => 'whatsapp',
    'to' => $phone,
    'type' => 'text',
    'text' => [
        'body' => $message,
    ],
];

echo "=== Sending WhatsApp Message ===\n\n";
echo "API URL: {$apiUrl}/{$senderNumber}/messages\n";
echo "Target: {$phone}\n\n";

// Send the request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "{$apiUrl}/{$senderNumber}/messages");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Bypass SSL for testing
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Bypass SSL for testing

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
if ($ch) {
    curl_close($ch);
}

echo "=== API Response ===\n\n";
echo "HTTP Status Code: {$httpCode}\n";

if ($curlError) {
    echo "cURL Error: {$curlError}\n";
}

echo "Response: {$response}\n\n";

if ($httpCode === 200) {
    echo "✅ SUCCESS: WhatsApp message sent successfully to employee 2812!\n";
    $responseData = json_decode($response, true);
    if (isset($responseData['messages'][0]['id'])) {
        echo "Message ID: {$responseData['messages'][0]['id']}\n";
    }
    echo "✅ Function 15 early leave violation notification delivered!\n";
} else {
    echo "❌ FAILED: Could not send WhatsApp message\n";
    echo "Please check the error response above\n";
}

echo "\n=== Test Complete ===\n";
echo "Note: If the message was not received, it may be due to WhatsApp Business API restrictions (24-hour window or template approval requirements)";