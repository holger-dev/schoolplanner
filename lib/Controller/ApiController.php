<?php

declare(strict_types=1);

namespace OCA\SchoolPlanner\Controller;

use OCA\SchoolPlanner\AppInfo\Application;
use OCA\SchoolPlanner\Service\AttachmentService;
use OCA\SchoolPlanner\Service\ImportExportService;
use OCA\SchoolPlanner\Service\JsonPlanService;
use OCA\SchoolPlanner\Service\MarkdownImportService;
use OCA\SchoolPlanner\Service\ParticipationService;
use OCA\SchoolPlanner\Service\PlannerService;
use OCA\SchoolPlanner\Service\PublishService;
use OCA\SchoolPlanner\Service\SettingsService;
use OCA\SchoolPlanner\Service\StudentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
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
		private ImportExportService $importExportService,
		private StudentService $studentService,
		private JsonPlanService $jsonPlanService,
		private MarkdownImportService $markdownImportService,
		private ParticipationService $participationService,
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
	public function setCourseDeck(int $courseId): DataResponse {
		return new DataResponse(
			$this->plannerService->updateCourseDeck($this->getUserId(), $courseId, $this->getJsonBody())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function createCourseLink(int $courseId): DataResponse {
		return new DataResponse(
			$this->plannerService->createCourseLink($this->getUserId(), $courseId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateCourseLink(int $linkId): DataResponse {
		return new DataResponse(
			$this->plannerService->updateCourseLink($this->getUserId(), $linkId, $this->getJsonBody())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteCourseLink(int $linkId): DataResponse {
		return new DataResponse(
			$this->plannerService->deleteCourseLink($this->getUserId(), $linkId)
		);
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
			$userId = $this->getUserId();
			$this->publishCourseQuietly($userId, $this->plannerService->getCourseIdForItem($userId, $itemId));
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
		$this->publishCourseQuietly($userId, $courseId);

		return new DataResponse([
			'ok' => true,
			'itemId' => $itemId,
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function reorderLessonItems(int $lessonId): DataResponse {
		$payload = $this->getJsonBody();
		$itemIds = is_array($payload['itemIds'] ?? null) ? $payload['itemIds'] : [];
		return new DataResponse(
			$this->plannerService->reorderLessonItems($this->getUserId(), $lessonId, $itemIds)
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function moveLessonItem(int $itemId): DataResponse {
		$payload = $this->getJsonBody();
		$targetLessonId = (int)($payload['targetLessonId'] ?? 0);
		if ($targetLessonId <= 0) {
			throw new \InvalidArgumentException('targetLessonId fehlt.');
		}

		return new DataResponse(
			$this->plannerService->moveItemToLesson($this->getUserId(), $itemId, $targetLessonId)
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getStudents(int $courseId): DataResponse {
		return new DataResponse($this->studentService->getOverview($this->getUserId(), $courseId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function createStudent(int $courseId): DataResponse {
		return new DataResponse(
			$this->studentService->createStudent($this->getUserId(), $courseId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function importStudents(int $courseId): DataResponse {
		return new DataResponse(
			$this->studentService->importStudents($this->getUserId(), $courseId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateStudent(int $studentId): DataResponse {
		return new DataResponse($this->studentService->updateStudent($this->getUserId(), $studentId, $this->getJsonBody()));
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteStudent(int $studentId): DataResponse {
		return new DataResponse($this->studentService->deleteStudent($this->getUserId(), $studentId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function createStudentGroup(int $courseId): DataResponse {
		return new DataResponse(
			$this->studentService->createGroup($this->getUserId(), $courseId, $this->getJsonBody()),
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function updateStudentGroup(int $groupId): DataResponse {
		return new DataResponse($this->studentService->updateGroup($this->getUserId(), $groupId, $this->getJsonBody()));
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteStudentGroup(int $groupId): DataResponse {
		return new DataResponse($this->studentService->deleteGroup($this->getUserId(), $groupId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function exportCoursePlan(int $courseId): DataDownloadResponse {
		$plan = $this->jsonPlanService->exportCoursePlan($this->getUserId(), $courseId);
		$json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		$fileName = 'schoolplanner-plan-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($plan['course']['name'] ?? 'kurs')) . '.json';
		return new DataDownloadResponse($json, $fileName, 'application/json');
	}

	/**
	 * @NoAdminRequired
	 */
	public function previewCoursePlan(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->jsonPlanService->previewCoursePlan($this->getUserId(), $courseId, $payload['plan'] ?? null)
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function importCoursePlan(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->jsonPlanService->importCoursePlan($this->getUserId(), $courseId, $payload['plan'] ?? null)
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function previewPlanFromFolder(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->markdownImportService->preview($this->getUserId(), $courseId, (string)($payload['path'] ?? ''))
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function importPlanFromFolder(int $courseId): DataResponse {
		$payload = $this->getJsonBody();
		return new DataResponse(
			$this->markdownImportService->import($this->getUserId(), $courseId, (string)($payload['path'] ?? ''))
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getParticipation(int $lessonId): DataResponse {
		return new DataResponse($this->participationService->getForLesson($this->getUserId(), $lessonId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function saveParticipation(int $lessonId): DataResponse {
		return new DataResponse(
			$this->participationService->saveForLesson($this->getUserId(), $lessonId, $this->getJsonBody())
		);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getParticipationOverview(int $courseId): DataResponse {
		return new DataResponse($this->participationService->getOverview($this->getUserId(), $courseId));
	}

	/**
	 * @NoAdminRequired
	 */
	public function uploadAttachment(int $itemId): DataResponse {
		$userId = $this->getUserId();
		$attachment = $this->attachmentService->uploadAttachment(
			$userId,
			$itemId,
			$this->request->getUploadedFile('file')
		);
		$this->publishCourseQuietly($userId, $this->plannerService->getCourseIdForItem($userId, $itemId));
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
	public function exportData(): DataDownloadResponse {
		$payload = $this->getJsonBody();
		$courseIds = array_values(array_filter(array_map(
			static fn ($value): int => (int)$value,
			is_array($payload['courseIds'] ?? null) ? $payload['courseIds'] : []
		), static fn (int $value): bool => $value > 0));
		$archive = $this->importExportService->exportArchive($this->getUserId(), $courseIds);
		return new DataDownloadResponse($archive['content'], $archive['fileName'], 'application/zip');
	}

	/**
	 * @NoAdminRequired
	 */
	public function importData(): DataResponse {
		$result = $this->importExportService->importArchive(
			$this->getUserId(),
			$this->request->getUploadedFile('file')
		);
		return new DataResponse([
			'ok' => true,
			'coursesImported' => $result['coursesImported'],
		], Http::STATUS_CREATED);
	}

	/**
	 * @NoAdminRequired
	 */
	public function publishCourse(int $courseId): DataResponse {
		return new DataResponse(
			$this->publishService->publishCourse($this->getUserId(), $courseId)
		);
	}

	/**
	 * Auto-publishing after edits is a convenience side effect. It must never
	 * make the primary action fail (e.g. when SFTP is not configured yet).
	 */
	private function publishCourseQuietly(string $userId, int $courseId): void {
		try {
			$this->publishService->publishCourse($userId, $courseId);
		} catch (\Throwable $exception) {
			// Publishing is optional; ignore failures here.
		}
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
