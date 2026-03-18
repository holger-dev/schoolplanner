<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Controller;

use OCA\SchoolPlanner\AppInfo\Application;
use OCA\SchoolPlanner\Service\AttachmentService;
use OCA\SchoolPlanner\Service\PlannerService;
use OCA\SchoolPlanner\Service\PublishService;
use OCA\SchoolPlanner\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\Http;

class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private PlannerService $plannerService,
		private AttachmentService $attachmentService,
		private SettingsService $settingsService,
		private PublishService $publishService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getBootstrap(): DataResponse {
		return new DataResponse($this->plannerService->getBootstrap($this->getUserId()));
	}

	/**
	 * @NoAdminRequired
	 */
	public function createCourse(): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->plannerService->createCourse(
				$this->getUserId(),
				$payload
			),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateCourse(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->plannerService->updateCourse(
				$this->getUserId(),
				$courseId,
				$payload
			)
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteCourse(int $courseId): DataResponse {
		$userId = $this->getUserId();
		$course = $this->plannerService->getCourse($userId, $courseId);
		foreach ($course['lessons'] as $lesson) {
			foreach ($lesson['items'] as $item) {
				$this->attachmentService->deleteAttachmentsForItem($userId, (int)$item['id']);
			}
		}

		$this->plannerService->deleteCourse($userId, $courseId);

		return new DataResponse([
			'ok' => true,
			'courseId' => $courseId,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function createLesson(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->plannerService->createLesson($this->getUserId(), $courseId, $payload),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function createLessonSeries(int $courseId): DataResponse {
		return new DataResponse(
			$this->plannerService->createLessonSeries($this->getUserId(), $courseId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function copyLesson(int $courseId): DataResponse {
		$userId = $this->getUserId();
		$payload = $this->getJsonBody();
		$sourceLessonId = (int)($payload['sourceLessonId'] ?? 0);
		if ($sourceLessonId <= 0) {
			throw new \InvalidArgumentException('sourceLessonId fehlt.');
		}

		$result = $this->plannerService->duplicateLessonToCourse($userId, $courseId, $sourceLessonId);
		foreach ($result['itemMap'] as $sourceItemId => $targetItemId) {
			$this->attachmentService->duplicateAttachmentsBetweenItems((int)$sourceItemId, (int)$targetItemId);
		}

		return new DataResponse(
			$this->plannerService->getLesson((int)$result['lesson']['id'], $userId),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateLesson(int $lessonId): DataResponse {
		return new DataResponse(
			$this->plannerService->updateLesson($this->getUserId(), $lessonId, $this->getJsonBody())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteLesson(int $lessonId): DataResponse {
		$this->plannerService->deleteLesson($this->getUserId(), $lessonId);
		return new DataResponse([
			'ok' => true,
			'lessonId' => $lessonId,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function createLessonItem(int $lessonId): DataResponse {
		return new DataResponse(
			$this->plannerService->createLessonItem($this->getUserId(), $lessonId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateLessonItem(int $itemId): DataResponse {
		$payload = $this->getJsonBody();
		$item = $this->plannerService->updateLessonItem($this->getUserId(), $itemId, $payload);
		if ((bool)($payload['triggerPublish'] ?? false) === true) {
			$this->publishService->publishCourseForItem($this->getUserId(), $itemId);
		}
		return new DataResponse($item);
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteLessonItem(int $itemId): DataResponse {
		$userId = $this->getUserId();
		$courseId = $this->plannerService->getCourseIdForItem($userId, $itemId);
		$this->attachmentService->deleteAttachmentsForItem($userId, $itemId);
		$this->plannerService->deleteLessonItem($userId, $itemId);
		$this->publishService->publishCourse($userId, $courseId);

		return new DataResponse([
			'ok' => true,
			'itemId' => $itemId,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function uploadAttachment(int $itemId): DataResponse {
		$attachment = $this->attachmentService->uploadAttachment(
			$this->getUserId(),
			$itemId,
			$this->request->getUploadedFile('file')
		);
		$this->publishService->publishCourseForItem($this->getUserId(), $itemId);
		return new DataResponse($attachment, Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 */
	public function saveSettings(): DataResponse {
		return new DataResponse(
			$this->settingsService->saveSettings($this->getUserId(), $this->getJsonBody())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function publishCourse(int $courseId): DataResponse {
		return new DataResponse(
			$this->publishService->publishCourse($this->getUserId(), $courseId)
		);
	}

	private function getUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated user');
		}

		return $user->getUID();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getJsonBody(): array {
		$params = $this->request->getParams();
		unset($params['_route']);
		return $params;
	}
}
