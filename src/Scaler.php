<?php

namespace Scaler;

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Exception;

class Scaler
{
	private $apiKey;
	private $accessToken = null;
	private $tokenFilePath;
	private $refreshAccessTokenUrl = 'https://api.scaler.pics/auth/api-key-token';
	private $signUrl = 'https://sign.scaler.pics/sign';
	private $client;

	public function __construct($apiKey, $tokenFilePath)
	{
		$this->apiKey = $apiKey;
		$this->tokenFilePath = $tokenFilePath;
		$this->client = new Client();
		$this->refreshAccessTokenIfNeeded();
	}

	public function transform($options)
	{
		$this->refreshAccessTokenIfNeeded();
		$start = microtime(true);

		if (!isset($options['output'])) {
			throw new Exception('No output provided');
		}

		if (array_keys($options['output']) !== range(0, count($options['output']) - 1)) {
			$outputs = [$options['output']];
		} else {
			$outputs = $options['output'];
		}

		$apiOutputs = array_map(function ($out) {
			return [
				'fit' => $out['fit'],
				'type' => $out['type'],
				'quality' => $out['quality'] ?? null,
				'upload' => $out['imageDelivery']['upload'] ?? null,
				'crop' => $out['crop'] ?? null,
			];
		}, $outputs);

		$options2 = [
			'input' => $options['input']['remoteUrl'] ?? 'body',
			'output' => $apiOutputs,
		];

		$startSignUrl = microtime(true);
		$res = $this->client->post($this->signUrl, [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->accessToken,
				'Content-Type' => 'application/json',
			],
			'json' => $options2,
		]);

		if ($res->getStatusCode() !== 200) {
			$text = $res->getBody()->getContents();
			throw new Exception("Failed to get transform url. status: " . $res->getStatusCode() . ", text: " . $text);
		}

		$json = json_decode($res->getBody()->getContents(), true);
		$signMs = (microtime(true) - $startSignUrl) * 1000;
		$url = $json['url'];

		$headers = [];
		$body = null;

		if (isset($options['input']['buffer'])) {
			$headers['Content-Type'] = 'application/x-octet-stream';
			$body = $options['input']['buffer'];
		} elseif (isset($options['input']['localPath'])) {
			$headers['Content-Type'] = 'application/x-octet-stream';
			$body = Psr7\Utils::tryFopen($options['input']['localPath'], 'r');
		}

		$startTransformTime = microtime(true);
		$res2 = $this->client->post($url, [
			'headers' => $headers,
			'body' => $body,
		]);

		if ($res2->getStatusCode() !== 200) {
			$text = $res2->getBody()->getContents();
			throw new Exception("Failed to transform image. status: " . $res2->getStatusCode() . ", text: " . $text);
		}

		$endTransformTime = microtime(true);
		$transfromResponse = json_decode($res2->getBody()->getContents(), true);
		$inputApiImage = $transfromResponse['inputImage'];
		$outputApiImages = $transfromResponse['outputImages'];
		$deleteUrl = $transfromResponse['deleteUrl'];
		$apiTimeStats = $transfromResponse['timeStats'];

		$sendImageMs = (($endTransformTime - $startTransformTime) * 1000) - $apiTimeStats['transformMs'] - ($apiTimeStats['uploadImagesMs'] ?? 0);
		$startGetImages = microtime(true);

		$promises = [];
		foreach ($outputApiImages as $i => $dest) {
			if (isset($dest['downloadUrl'])) {
				$dlUrl = $dest['downloadUrl'];
				if (isset($outputs[$i]['imageDelivery']['saveToLocalPath'])) {
					$destPath = $outputs[$i]['imageDelivery']['saveToLocalPath'];
					$promises[] = $this->downloadImage($dlUrl, $destPath);
				} else {
					$promises[] = $this->downloadImageBuffer($dlUrl);
				}
			} else {
				$promises[] = ['image' => 'uploaded'];
			}
		}

		$outputImageResults = $promises;
		$getImagesMs = $apiTimeStats['uploadImagesMs'] ?? (microtime(true) - $startGetImages) * 1000;
		$deleteBody = ['images' => array_map(function ($dest) {
			return $dest['fileId'];
		}, array_filter($outputApiImages, function ($dest) {
			return isset($dest['fileId']);
		}))];

		$this->client->delete($deleteUrl, [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'json' => $deleteBody,
		]);

		$totalMs = (microtime(true) - $start) * 1000;
		$outputImages = array_map(function ($dest, $i) use ($outputImageResults) {
			return [
				'fit' => $dest['fit'],
				'pixelSize' => $dest['pixelSize'],
				'image' => $outputImageResults[$i]['image'],
			];
		}, $outputApiImages, array_keys($outputApiImages));

		return [
			'inputImage' => $inputApiImage,
			'outputImage' => is_array($options['output']) ? $outputImages : $outputImages[0],
			'timeStats' => [
				'signMs' => $signMs,
				'sendImageMs' => $sendImageMs,
				'transformMs' => $apiTimeStats['transformMs'],
				'getImagesMs' => $getImagesMs,
				'totalMs' => $totalMs,
			],
		];
	}

	public function transformAsync($options)
	{
		$promise = new Promise(function () use (&$promise, $options) {
			$this->refreshAccessTokenIfNeeded();
			$start = microtime(true);

			if (!isset($options['output'])) {
				throw new Exception('No output provided');
			}

			if (array_keys($options['output']) !== range(0, count($options['output']) - 1)) {
				$outputs = [$options['output']];
			} else {
				$outputs = $options['output'];
			}

			$apiOutputs = array_map(function ($out) {
				return [
					'fit' => $out['fit'],
					'type' => $out['type'],
					'quality' => $out['quality'] ?? null,
					'upload' => $out['imageDelivery']['upload'] ?? null,
					'crop' => $out['crop'] ?? null,
				];
			}, $outputs);

			$options2 = [
				'input' => $options['input']['remoteUrl'] ?? 'body',
				'output' => $apiOutputs,
			];

			$startSignUrl = microtime(true);
			$res = $this->client->post($this->signUrl, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->accessToken,
					'Content-Type' => 'application/json',
				],
				'json' => $options2,
			]);
			echo "past sign url " . $res->getStatusCode() . "\n";
			if ($res->getStatusCode() !== 200) {
				$text = $res->getBody()->getContents();
				throw new Exception("Failed to get transform url. status: " . $res->getStatusCode() . ", text: " . $text);
			}

			$json = json_decode($res->getBody()->getContents(), true);
			$signMs = (microtime(true) - $startSignUrl) * 1000;
			$url = $json['url'];

			$headers = [];
			$body = null;

			if (isset($options['input']['buffer'])) {
				$headers['Content-Type'] = 'application/x-octet-stream';
				$body = $options['input']['buffer'];
			} elseif (isset($options['input']['localPath'])) {
				$headers['Content-Type'] = 'application/x-octet-stream';
				$body = Psr7\Utils::tryFopen($options['input']['localPath'], 'r');
			}

			$startTransformTime = microtime(true);
			$res2 = $this->client->post($url, [
				'headers' => $headers,
				'body' => $body,
			]);
			echo "past transform call" . $res2->getStatusCode() . "\n";
			if ($res2->getStatusCode() !== 200) {
				$text = $res2->getBody()->getContents();
				throw new Exception("Failed to transform image. status: " . $res2->getStatusCode() . ", text: " . $text);
			}

			$endTransformTime = microtime(true);
			$transfromResponse = json_decode($res2->getBody()->getContents(), true);
			$inputApiImage = $transfromResponse['inputImage'];
			$outputApiImages = $transfromResponse['outputImages'];
			$deleteUrl = $transfromResponse['deleteUrl'];
			$apiTimeStats = $transfromResponse['timeStats'];

			$sendImageMs = (($endTransformTime - $startTransformTime) * 1000) - $apiTimeStats['transformMs'] - ($apiTimeStats['uploadImagesMs'] ?? 0);
			$startGetImages = microtime(true);

			$promises = [];
			foreach ($outputApiImages as $i => $dest) {
				echo 'adding dst image promise ' . $i . "\n";
				if (isset($dest['downloadUrl'])) {
					echo 'adding dl image promise ' . $i . "\n";
					$dlUrl = $dest['downloadUrl'];
					$finalPromise = new Promise(function () use (&$finalPromise, $dlUrl, $outputs, $i, $outputApiImages, $deleteUrl, $dest) {
						$dlPromise = null;
						if (isset($outputs[$i]['imageDelivery']['saveToLocalPath'])) {
							$destPath = $outputs[$i]['imageDelivery']['saveToLocalPath'];
							$dlPromise = $this->downloadImageAsync($dlUrl, $destPath);
						} else {
							$dlPromise = $this->downloadImageBufferAsync($dlUrl);
						}
						echo "before waiting for dl promise" . $dlUrl . "\n";
						$dlResult = $dlPromise->wait();
						echo "after waiting for dl promise" . $dlUrl . "\n";

						$deleteBody = ['images' => array_map(function ($dest) {
							return $dest['fileId'];
						}, array_filter($outputApiImages, function ($dest) {
							return isset($dest['fileId']);
						}))];

						$deletePromise = $this->client->deleteAsync($deleteUrl, [
							'headers' => [
								'Content-Type' => 'application/json',
							],
							'json' => $deleteBody,
						]);
						$deletePromise->then(function ($res) {
							echo "delete response " . $res->getStatusCode() . "\n";
						});
						// $deletePromise->wait(); // do I need to wait for this?

						$finalPromise->resolve([
							'fit' => $dest['fit'],
							'pixelSize' => $dest['pixelSize'],
							'image' => $dlResult['image'],
						]);

						// $finalPromise->resolve([
						// 	'inputImage' => $inputApiImage,
						// 	'outputImage' => is_array($options['output']) ? $outputImages : $outputImages[0],
						// 	'timeStats' => [
						// 		'signMs' => $signMs,
						// 		'sendImageMs' => $sendImageMs,
						// 		'transformMs' => $apiTimeStats['transformMs'],
						// 		'getImagesMs' => $getImagesMs,
						// 		'totalMs' => $totalMs,
						// 	],
						// ]);
						echo "after inner resolve\n";
					});
					$promises[] = $finalPromise;
				} else {
					$promises[] = new FulfilledPromise(['image' => 'uploaded']);
				}
			}

			echo "we are before unwrapping promises\n";
			$responses = PromiseUtils::settle($promises)->wait();
			echo "we are after unwrapping promises\n";
			echo "responses are " . json_encode($responses) . "\n";

			$getImagesMs = $apiTimeStats['uploadImagesMs'] ?? (microtime(true) - $startGetImages) * 1000;
			$totalMs = (microtime(true) - $start) * 1000;

			$promise->resolve([
				'inputImage' => $inputApiImage,
				'outputImage' => is_array($options['output']) ? $responses : $responses[0],
				'timeStats' => [
					'signMs' => $signMs,
					'sendImageMs' => $sendImageMs,
					'transformMs' => $apiTimeStats['transformMs'],
					'getImagesMs' => $getImagesMs,
					'totalMs' => $totalMs,
				],
			]);
		});
		return $promise;
	}

	private function downloadImageAsync($url, $path)
	{
		return $this->client->getAsync($url, ['sink' => $path])->then(function ($res) use ($path) {
			if ($res->getStatusCode() !== 200) {
				throw new Exception("Failed to download image. status: " . $res->getStatusCode());
			}
			return ['image' => $path];
		});
	}

	private function downloadImageBufferAsync($url)
	{
		return $this->client->getAsync($url)->then(function ($res) {
			if ($res->getStatusCode() !== 200) {
				throw new Exception("Failed to download image. status: " . $res->getStatusCode());
			}
			return ['image' => $res->getBody()->getContents()];
		});
	}

	private function refreshAccessTokenIfNeeded()
	{
		$shouldRefresh = false;
		if ($this->accessToken === null) {
			$this->accessToken = $this->loadAccessTokenFromFile();
		}

		if ($this->accessToken === null) {
			$shouldRefresh = true;
		} else {
			$tokenParts = explode('.', $this->accessToken);
			$jsonToken = base64_decode($tokenParts[1]);
			$decoded = json_decode($jsonToken, true);
			$now = time();
			if ($now >= $decoded['exp']) {
				$shouldRefresh = true;
			}
		}

		if ($shouldRefresh) {
			$this->refreshAccessToken();
		}
	}

	private function refreshAccessToken()
	{
		$res = $this->client->post($this->refreshAccessTokenUrl, [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->apiKey,
			],
		]);

		if ($res->getStatusCode() !== 200) {
			$text = $res->getBody()->getContents();
			throw new Exception("Failed to refresh the access token. status: " . $res->getStatusCode() . ", text: " . $text);
		}

		$json = json_decode($res->getBody()->getContents(), true);
		$this->accessToken = $json['accessToken'];
		$this->saveAccessTokenToFile($this->accessToken);
	}

	private function loadAccessTokenFromFile()
	{
		if (!file_exists($this->tokenFilePath)) {
			return null;
		}

		$handle = fopen($this->tokenFilePath, 'r');
		if (flock($handle, LOCK_SH)) {
			$token = fread($handle, filesize($this->tokenFilePath));
			flock($handle, LOCK_UN);
			fclose($handle);
			return $token;
		}

		fclose($handle);
		return null;
	}

	private function saveAccessTokenToFile($token)
	{
		$handle = fopen($this->tokenFilePath, 'c');
		if (flock($handle, LOCK_EX)) {
			ftruncate($handle, 0);
			fwrite($handle, $token);
			fflush($handle);
			flock($handle, LOCK_UN);
		}
		fclose($handle);
	}

	private function downloadImage($url, $path)
	{
		$res = $this->client->get($url, ['sink' => $path]);

		if ($res->getStatusCode() !== 200) {
			$text = $res->getBody()->getContents();
			throw new Exception("Failed to download image. status: " . $res->getStatusCode() . ", text: " . $text);
		}

		return ['image' => $path];
	}

	private function downloadImageBuffer($url)
	{
		$res = $this->client->get($url);

		if ($res->getStatusCode() !== 200) {
			$text = $res->getBody()->getContents();
			throw new Exception("Failed to download image. status: " . $res->getStatusCode() . ", text: " . $text);
		}

		return ['image' => $res->getBody()->getContents()];
	}
}
