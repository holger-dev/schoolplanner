<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'api#getBootstrap', 'url' => '/api/bootstrap', 'verb' => 'GET'],
		['name' => 'api#createCourse', 'url' => '/api/courses', 'verb' => 'POST'],
		['name' => 'api#updateCourse', 'url' => '/api/courses/{courseId}', 'verb' => 'PUT'],
		['name' => 'api#deleteCourse', 'url' => '/api/courses/{courseId}', 'verb' => 'DELETE'],
		['name' => 'api#deleteCourse', 'url' => '/api/courses/{courseId}/delete', 'verb' => 'POST'],
		['name' => 'api#createLesson', 'url' => '/api/courses/{courseId}/lessons', 'verb' => 'POST'],
		['name' => 'api#updateLesson', 'url' => '/api/lessons/{lessonId}', 'verb' => 'PUT'],
		['name' => 'api#deleteLesson', 'url' => '/api/lessons/{lessonId}', 'verb' => 'DELETE'],
		['name' => 'api#deleteLesson', 'url' => '/api/lessons/{lessonId}/delete', 'verb' => 'POST'],
		['name' => 'api#createLessonItem', 'url' => '/api/lessons/{lessonId}/items', 'verb' => 'POST'],
		['name' => 'api#updateLessonItem', 'url' => '/api/items/{itemId}', 'verb' => 'PUT'],
		['name' => 'api#deleteLessonItem', 'url' => '/api/items/{itemId}', 'verb' => 'DELETE'],
		['name' => 'api#deleteLessonItem', 'url' => '/api/items/{itemId}/delete', 'verb' => 'POST'],
		['name' => 'api#uploadAttachment', 'url' => '/api/items/{itemId}/attachments', 'verb' => 'POST'],
		['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
		['name' => 'api#publishCourse', 'url' => '/api/courses/{courseId}/publish', 'verb' => 'POST'],
	],
];
