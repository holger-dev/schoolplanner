<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\AppInfo;

$schoolplannerAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($schoolplannerAutoload)) {
	require_once $schoolplannerAutoload;
}

use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\App;

class Application extends App implements IBootstrap {
	public const APP_ID = 'schoolplanner';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
