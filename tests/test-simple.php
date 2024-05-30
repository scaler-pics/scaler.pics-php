
<?php

require './Scaler.php';

$apiKey = $argv[1] ?? '';

$scaler = new Scaler($apiKey, './test-data/access-token.txt');

$options = [
	'input' => ['localPath' => 'test-data/test.heic'],
	'output' => [
		'type' => 'jpeg',
		'fit' => ['width' => 1024, 'height' => 1024],
		'quality' => 0.8,
		'imageDelivery' => [
			'saveToLocalPath' => 'test-data/test.jpg'
		]
	]
];

try {
	$result = $scaler->transform($options);
	echo 'result: ' . print_r($result, true);
} catch (Exception $e) {
	echo 'Error: ' . $e->getMessage();
	echo 'Stack trace: ' . $e->getTraceAsString() . "\n";
}
