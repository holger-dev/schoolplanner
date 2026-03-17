<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\AppInfo;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OCP\AppFramework\App;

class Application extends App {
	public const APP_ID = 'schoolplanner';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}
}
