<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Pool;

class Scaler
{
	// Class implementation goes here (refer to the previously provided PHP class code)
}

// Initialize Scaler with your API key and token file path
$scaler = new Scaler('your-api-key', '/path/to/token/file.txt');

// Define transform options for multiple images
$optionsList = [
	[
		'input' => [
			'remoteUrl' => 'https://example.com/image1.jpg',
		],
		'output' => [
			'fit' => 'cover',
			'type' => 'jpeg',
			'quality' => 80,
			'imageDelivery' => [
				'saveToLocalPath' => '/path/to/save/image1.jpg',
			],
		],
	],
	[
		'input' => [
			'remoteUrl' => 'https://example.com/image2.jpg',
		],
		'output' => [
			'fit' => 'contain',
			'type' => 'png',
			'quality' => 90,
			'imageDelivery' => [
				'saveToLocalPath' => '/path/to/save/image2.png',
			],
		],
	],
	// Add more image transform options as needed
];

// Function to handle the transform operation
$transformImage = function ($options) use ($scaler) {
	try {
		return $scaler->transform($options);
	} catch (Exception $e) {
		return 'Error: ' . $e->getMessage();
	}
};

$client = new Client();
$requests = function ($optionsList) use ($transformImage) {
	foreach ($optionsList as $options) {
		yield function () use ($transformImage, $options) {
			return $transformImage($options);
		};
	}
};

// Pool for concurrent requests
$pool = new Pool($client, $requests($optionsList), [
	'concurrency' => 5,
	'fulfilled' => function ($response, $index) {
		echo "Image {$index} transformed successfully\n";
		print_r($response);
	},
	'rejected' => function ($reason, $index) {
		echo "Image {$index} failed to transform: {$reason}\n";
	},
]);

// Initiate the pool
$promise = $pool->promise();
$promise->wait();
