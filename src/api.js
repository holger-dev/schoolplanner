import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const jsonHeaders = {
	'Content-Type': 'application/json',
}

export const fetchBootstrap = async () => {
	const { data } = await axios.get(generateUrl('/apps/schoolplanner/api/bootstrap'))
	return data
}

export const createCourse = async (payload) => {
	const { data } = await axios.post(generateUrl('/apps/schoolplanner/api/courses'), payload, { headers: jsonHeaders })
	return data
}

export const updateCourse = async (courseId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/courses/${courseId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteCourse = async (courseId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/delete`))
	return data
}

export const createLesson = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/lessons`), payload, { headers: jsonHeaders })
	return data
}

export const createLessonSeries = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/lesson-series`), payload, { headers: jsonHeaders })
	return data
}

export const copyLesson = async (courseId, sourceLessonId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/copy-lesson`), { sourceLessonId }, { headers: jsonHeaders })
	return data
}

export const updateLesson = async (lessonId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteLesson = async (lessonId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}/delete`))
	return data
}

export const createLessonItem = async (lessonId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}/items`), payload, { headers: jsonHeaders })
	return data
}

export const updateLessonItem = async (itemId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/items/${itemId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteLessonItem = async (itemId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/items/${itemId}/delete`))
	return data
}

export const reorderLessonItems = async (lessonId, itemIds) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}/items/reorder`), { itemIds }, { headers: jsonHeaders })
	return data
}

export const moveLessonItem = async (itemId, targetLessonId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/items/${itemId}/move`), { targetLessonId }, { headers: jsonHeaders })
	return data
}

export const uploadAttachment = async (itemId, file, onUploadProgress) => {
	const formData = new FormData()
	formData.append('file', file)
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/items/${itemId}/attachments`), formData, {
		onUploadProgress,
	})
	return data
}

export const saveSettings = async (payload) => {
	const { data } = await axios.put(generateUrl('/apps/schoolplanner/api/settings'), payload, { headers: jsonHeaders })
	return data
}

export const exportPlannerData = async (courseIds = []) => {
	const response = await axios.post(generateUrl('/apps/schoolplanner/api/export'), { courseIds }, {
		headers: jsonHeaders,
		responseType: 'blob',
	})
	return {
		blob: response.data,
		fileName: response.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1] || 'schoolplanner-export.zip',
	}
}

export const importPlannerData = async (file) => {
	const formData = new FormData()
	formData.append('file', file)
	const { data } = await axios.post(generateUrl('/apps/schoolplanner/api/import'), formData)
	return data
}

export const publishCourse = async (courseId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/publish`))
	return data
}

// #9 Zentrale Links pro Kurs
export const createCourseLink = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/links`), payload, { headers: jsonHeaders })
	return data
}

export const updateCourseLink = async (linkId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/links/${linkId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteCourseLink = async (linkId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/links/${linkId}/delete`))
	return data
}

// #2 Deck-Anbindung
export const setCourseDeck = async (courseId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/deck`), payload, { headers: jsonHeaders })
	return data
}

export const fetchDeckBoards = async () => {
	const { data } = await axios.get(generateUrl('/apps/deck/api/v1.0/boards'), { headers: { 'OCS-APIRequest': 'true' } })
	return Array.isArray(data) ? data : []
}

export const fetchDeckStacks = async (boardId) => {
	const { data } = await axios.get(generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks`), { headers: { 'OCS-APIRequest': 'true' } })
	return Array.isArray(data) ? data : []
}

export const createDeckCard = async (boardId, stackId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/deck/api/v1.0/boards/${boardId}/stacks/${stackId}/cards`), payload, {
		headers: { ...jsonHeaders, 'OCS-APIRequest': 'true' },
	})
	return data
}

// #7 Schüler:innen
export const fetchStudents = async (courseId) => {
	const { data } = await axios.get(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/students`))
	return data
}

export const createStudent = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/students`), payload, { headers: jsonHeaders })
	return data
}

export const importStudents = async (courseId, text) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/students/import`), { text }, { headers: jsonHeaders })
	return data
}

export const updateStudent = async (studentId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/students/${studentId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteStudent = async (studentId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/students/${studentId}/delete`))
	return data
}

export const createStudentGroup = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/student-groups`), payload, { headers: jsonHeaders })
	return data
}

export const updateStudentGroup = async (groupId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/student-groups/${groupId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteStudentGroup = async (groupId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/student-groups/${groupId}/delete`))
	return data
}

// Mitarbeit
export const fetchParticipation = async (lessonId) => {
	const { data } = await axios.get(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}/participation`))
	return data
}

export const saveParticipation = async (lessonId, scale, entries) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}/participation`), { scale, entries }, { headers: jsonHeaders })
	return data
}

export const fetchParticipationOverview = async (courseId) => {
	const { data } = await axios.get(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/participation`))
	return data
}

// #8 Planung als JSON (Export / Vorschau / Import)
export const exportCoursePlan = async (courseId) => {
	const response = await axios.get(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan`), {
		responseType: 'blob',
	})
	return {
		blob: response.data,
		fileName: response.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1] || 'schoolplanner-plan.json',
	}
}

export const fetchCoursePlan = async (courseId) => {
	const response = await axios.get(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan`))
	return response.data
}

export const previewCoursePlan = async (courseId, plan) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan/preview`), { plan }, { headers: jsonHeaders })
	return data
}

export const importCoursePlan = async (courseId, plan) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan/import`), { plan }, { headers: jsonHeaders })
	return data
}

export const previewPlanFromFolder = async (courseId, path) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan/folder/preview`), { path }, { headers: jsonHeaders })
	return data
}

export const importPlanFromFolder = async (courseId, path) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/plan/folder/import`), { path }, { headers: jsonHeaders })
	return data
}
