<?php

declare(strict_types=1);

if ($argc < 3) {
	fwrite(STDERR, "Usage: php file_from_env.php <env_name> <target_file>\n");
	exit(1);
}

$envName = $argv[1];
$targetFile = $argv[2];
$content = getenv($envName);

if ($content === false || trim($content) === '') {
	exit(0);
}

$directory = dirname($targetFile);
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
	fwrite(STDERR, "Could not create directory: {$directory}\n");
	exit(1);
}

if (file_put_contents($targetFile, $content) === false) {
	fwrite(STDERR, "Could not write file: {$targetFile}\n");
	exit(1);
}
