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

export const createLesson = async (courseId, payload) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/lessons`), payload, { headers: jsonHeaders })
	return data
}

export const updateLesson = async (lessonId, payload) => {
	const { data } = await axios.put(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}`), payload, { headers: jsonHeaders })
	return data
}

export const deleteLesson = async (lessonId) => {
	const { data } = await axios.delete(generateUrl(`/apps/schoolplanner/api/lessons/${lessonId}`))
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

export const uploadAttachment = async (itemId, file) => {
	const formData = new FormData()
	formData.append('file', file)
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/items/${itemId}/attachments`), formData)
	return data
}

export const saveSettings = async (payload) => {
	const { data } = await axios.put(generateUrl('/apps/schoolplanner/api/settings'), payload, { headers: jsonHeaders })
	return data
}

export const publishCourse = async (courseId) => {
	const { data } = await axios.post(generateUrl(`/apps/schoolplanner/api/courses/${courseId}/publish`))
	return data
}
