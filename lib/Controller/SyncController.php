<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Controller;

use OCA\SchoolPlanner\AppInfo\Application;
use OCA\SchoolPlanner\Service\SyncService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Schnittstelle für den Offline-Client (School Planner Offline).
 *
 * Bewusst ein eigener Controller unter /api/v1/ statt die bestehenden Routen
 * aufzubohren: Diese Endpunkte werden von einem externen Programm mit einem
 * App-Passwort aufgerufen und brauchen deshalb @NoCSRFRequired. Die Routen der
 * Weboberfläche behalten ihre CSRF-Absicherung unverändert.
 */
class SyncController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private SyncService $syncService,
		private IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Verbindungstest beim Einrichten: Wer bin ich, welche Fassung läuft hier?
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function info(): DataResponse {
		return new DataResponse(
			$this->syncService->info($this->getUserId(), $this->appVersion())
		);
	}

	/**
	 * Planung im Zeitfenster lesen.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function pull(string $from = '', string $to = ''): DataResponse {
		return new DataResponse(
			$this->syncService->pull($this->getUserId(), $from, $to)
		);
	}

	/**
	 * Mitarbeit und Elementzustände zurückschreiben.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function push(): DataResponse {
		$params = $this->request->getParams();
		unset($params['_route']);

		return new DataResponse(
			$this->syncService->push($this->getUserId(), $params)
		);
	}

	/**
	 * Antwort auf die CORS-Vorabanfrage des Browsers.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function preflightedCors(): DataResponse {
		$response = new DataResponse([], Http::STATUS_OK);
		$origin = $this->request->getHeader('Origin');
		$response->addHeader('Access-Control-Allow-Origin', $origin !== '' ? $origin : '*');
		$response->addHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, OCS-APIRequest');
		$response->addHeader('Access-Control-Max-Age', '1728000');
		$response->addHeader('Access-Control-Allow-Credentials', 'false');

		return $response;
	}

	private function appVersion(): string {
		try {
			return $this->appManager->getAppVersion(Application::APP_ID);
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated user');
		}

		return $user->getUID();
	}
}
