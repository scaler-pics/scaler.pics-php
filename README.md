# Scaler

A PHP library for image scaling, conversion and document thumbnail generation.

## Installation

```sh
composer require scaler/scaler
```

## Usage

Initialize the scaler object with your API key. Then use it to transform images as needed. You will also need to specify the path to a file where library can save access token. Make sure the path is readable and writable by the PHP.

```php
<?php

require 'vendor/autoload.php';

use Scaler\Scaler;

$scaler = new Scaler('YOUR_API_KEY', '/path/to/access-token.txt');

$options = [
	'input' => ['localPath' => '/path/to/large-image.heic'],
	'output' => [
		'type' => 'jpeg',
		'fit' => ['width' => 512, 'height' => 512],
		'quality' => 0.8
	]
];

$result = $scaler->transform($options);
$outputImage = result['outputImage'];
```

Get API key from [Scaler](https://scaler.pics)
