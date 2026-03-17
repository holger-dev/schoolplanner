<template>
	<NcContent app-name="schoolplanner">
		<NcAppNavigation aria-label="Kurse">
			<template #list>
				<NcAppNavigationNew text="Kurs anlegen" @click="openCreateCourseModal" />

				<NcAppNavigationItem
					v-for="course in courses"
					:key="course.id"
					:name="course.name"
					:title="course.name"
					:active="selectedCourseId === course.id"
					:editable="true"
					edit-label="Kurs umbenennen"
					edit-placeholder="z. B. 8a"
					@click="selectCourse(course.id)"
					@update:name="renameCourse(course, $event)" />
			</template>

			<template #footer>
				<NcAppNavigationSettings name="Publishing-Einstellungen">
					<div class="settings-panel">
						<NcTextField
							v-model="settingsDraft.publicBaseUrl"
							label="Webadresse"
							placeholder="https://school.heidkamp.dev/" />
						<NcTextField
							v-model="settingsDraft.sftpUsername"
							label="SFTP-Benutzername"
							placeholder="deploy" />
						<NcTextField
							v-model="settingsDraft.sftpPassword"
							label="SFTP-Passwort"
							type="password"
							placeholder="Passwort" />
						<NcButton type="primary" @click="persistSettings">Speichern</NcButton>
					</div>
				</NcAppNavigationSettings>
			</template>
		</NcAppNavigation>

		<NcAppContent
			:list-size="34"
			:list-min-width="28"
			:list-max-width="42"
			layout="vertical-split"
			:show-details="Boolean(selectedLesson)"
			page-heading="School Planner"
			page-title="School Planner"
			@update:showDetails="handleShowDetailsUpdate">
			<template #list>
				<NcAppContentList :show-details="Boolean(selectedLesson)">
					<div class="list-panel">
					<div class="list-panel__header">
						<div>
							<h2>{{ selectedCourse ? selectedCourse.name : 'Kein Kurs ausgewaehlt' }}</h2>
							<p>{{ selectedCourse ? selectedCourse.description || 'Noch keine Kursbeschreibung.' : 'Bitte links einen Kurs anlegen oder auswaehlen.' }}</p>
						</div>
					</div>

					<div v-if="selectedCourse" class="list-panel__divider" />

					<div class="list-panel__actions" v-if="selectedCourse">
						<NcButton type="primary" @click="handleCreateLesson">Neue Stunde</NcButton>
						<NcButton @click="handlePublishCourse">Makroplanung veroeffentlichen</NcButton>
					</div>

						<NcEmptyContent
							v-if="!selectedCourse"
							name="Noch kein Kurs"
							description="Lege links einen Kurs an oder waehle einen vorhandenen Kurs aus." />

						<div v-else class="lesson-list">
							<NcAppNavigationItem
								v-for="lesson in sortedLessons"
								:key="lesson.id"
								:name="lesson.title"
								:title="lesson.title"
								:active="selectedLessonId === lesson.id"
								@click="selectLesson(lesson.id)">
								<template #default>
									<div class="lesson-entry">
										<strong>{{ formatDate(lesson.lessonDate) }}</strong>
										<span>{{ lesson.title }}</span>
										<small>{{ lesson.items.filter((item) => item.published).length }} veroeffentlicht</small>
									</div>
								</template>
							</NcAppNavigationItem>
						</div>
					</div>
				</NcAppContentList>
			</template>

			<NcAppContentDetails>
				<div v-if="selectedLesson" class="details-panel">
					<div class="details-panel__header">
						<div>
							<h2>Stunde</h2>
							<p>Datum, Thema und Beschreibung der Unterrichtseinheit.</p>
						</div>
						<div class="details-panel__actions">
							<NcButton @click="removeLesson">Stunde loeschen</NcButton>
							<NcButton type="primary" @click="saveLesson">Stunde speichern</NcButton>
						</div>
					</div>

					<div class="details-grid">
						<NcDateTimePickerNative
							:model-value="lessonDraftDate"
							label="Datum"
							type="date"
							@update:model-value="lessonDraftDate = $event" />
						<NcTextField
							v-model="lessonDraft.title"
							label="Thema"
							placeholder="z. B. Einfuehrung Python" />
					</div>

					<NcTextArea
						v-model="lessonDraft.description"
						label="Beschreibung (Markdown)"
						resize="vertical" />

					<div class="details-panel__header details-panel__header--sub">
						<div>
							<h2>Stundenablauf</h2>
							<p>Elemente koennen einzeln veroeffentlicht werden.</p>
						</div>
						<NcButton @click="handleCreateItem">Element anlegen</NcButton>
					</div>

					<NcEmptyContent
						v-if="selectedLesson.items.length === 0"
						name="Noch keine Ablauf-Elemente"
						description="Lege das erste Element fuer diese Stunde an." />

					<div v-else class="item-list">
						<NcNoteCard
							v-for="item in selectedLesson.items"
							:key="item.id"
							type="info">
							<div class="item-form">
								<div class="item-form__header">
									<div class="item-form__main">
										<NcTextField
											v-model="item.title"
											label="Titel"
											placeholder="Titel des Elements" />
									</div>
									<div class="item-form__controls">
										<NcCheckboxRadioSwitch
											:model-value="item.published"
											type="switch"
											@update:model-value="toggleItemPublished(item, $event)">
											Veroeffentlichen
										</NcCheckboxRadioSwitch>
										<div class="item-form__order-buttons">
											<NcButton :disabled="isFirstItem(item)" @click="moveItem(item, -1)">Nach oben</NcButton>
											<NcButton :disabled="isLastItem(item)" @click="moveItem(item, 1)">Nach unten</NcButton>
										</div>
									</div>
								</div>

								<NcTextArea
									v-model="item.description"
									label="Beschreibung (Markdown)"
									input-class="item-form__textarea"
									resize="vertical" />

								<div class="item-form__attachments">
									<div class="item-form__attachments-header">
										<strong>Dateien</strong>
										<div>
											<input
												:id="`attachment-input-${item.id}`"
												class="item-form__file-input"
												type="file"
												@change="handleAttachmentSelected(item, $event)">
											<NcButton @click="openAttachmentPicker(item.id)">Datei hochladen</NcButton>
										</div>
									</div>
									<ul v-if="item.attachments?.length" class="item-form__attachment-list">
										<li v-for="attachment in item.attachments" :key="attachment.id">
											{{ attachment.fileName }}
										</li>
									</ul>
									<p v-else>Noch keine Dateien hochgeladen.</p>
								</div>

								<div class="item-form__actions">
									<NcButton type="primary" @click="saveItem(item)">Element speichern</NcButton>
								</div>
							</div>
						</NcNoteCard>
					</div>
				</div>

				<NcEmptyContent
					v-else
					name="Keine Stunde ausgewaehlt"
					description="Waehle links eine Stunde aus, um den Ablauf zu bearbeiten." />
			</NcAppContentDetails>
		</NcAppContent>

		<NcModal v-if="courseModalOpen" size="normal" :name="courseModalTitle" @close="closeCourseModal">
			<div class="dialog-body">
				<div class="dialog-header">
					<h2>{{ courseModalTitle }}</h2>
					<p>{{ courseDraft.id ? 'Passe Name und Beschreibung des Kurses an.' : 'Lege einen neuen Kurs mit Name und Beschreibung an.' }}</p>
				</div>

				<NcTextField
					v-model="courseDraft.name"
					label="Name" />
				<NcTextArea
					v-model="courseDraft.description"
					label="Beschreibung"
					resize="vertical" />
				<div class="dialog-actions">
					<NcButton @click="closeCourseModal">Abbrechen</NcButton>
					<NcButton type="primary" @click="submitCourseModal">{{ courseDraft.id ? 'Speichern' : 'Kurs anlegen' }}</NcButton>
				</div>
			</div>
		</NcModal>
	</NcContent>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcAppContent,
	NcAppContentDetails,
	NcAppContentList,
	NcAppNavigation,
	NcAppNavigationItem,
	NcAppNavigationNew,
	NcAppNavigationSettings,
	NcButton,
	NcCheckboxRadioSwitch,
	NcContent,
	NcDateTimePickerNative,
	NcDialog,
	NcEmptyContent,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	createCourse,
	deleteLesson,
	createLesson,
	createLessonItem,
	fetchBootstrap,
	publishCourse,
	saveSettings,
	uploadAttachment,
	updateCourse,
	updateLesson,
	updateLessonItem,
} from './api'

export default {
	name: 'App',
	components: {
		NcAppContent,
		NcAppContentDetails,
		NcAppContentList,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcAppNavigationSettings,
		NcButton,
		NcCheckboxRadioSwitch,
		NcContent,
		NcDateTimePickerNative,
		NcDialog,
		NcEmptyContent,
		NcModal,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},
	data() {
		return {
			courses: [],
			selectedCourseId: null,
			selectedLessonId: null,
			courseModalOpen: false,
			courseDraft: {
				id: null,
				name: '',
				description: '',
			},
			settingsDraft: {
				sftpUsername: '',
				sftpPassword: '',
				publicBaseUrl: '',
			},
			lessonDraft: {
				id: null,
				lessonDate: '',
				title: '',
				description: '',
			},
		}
	},
	computed: {
		selectedCourse() {
			return this.courses.find((course) => course.id === this.selectedCourseId) || null
		},
		selectedLesson() {
			return this.selectedCourse?.lessons.find((lesson) => lesson.id === this.selectedLessonId) || null
		},
		sortedLessons() {
			return [...(this.selectedCourse?.lessons || [])].sort((a, b) => a.lessonDate.localeCompare(b.lessonDate))
		},
		courseModalTitle() {
			return this.courseDraft.id ? 'Kurs bearbeiten' : 'Kurs anlegen'
		},
		lessonDraftDate: {
			get() {
				return this.lessonDraft.lessonDate ? new Date(`${this.lessonDraft.lessonDate}T00:00:00`) : null
			},
			set(value) {
				this.lessonDraft.lessonDate = value instanceof Date
					? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
					: ''
			},
		},
	},
	watch: {
		selectedLesson: {
			immediate: true,
			handler(lesson) {
				if (!lesson) {
					this.lessonDraft = { id: null, lessonDate: '', title: '', description: '' }
					return
				}

				this.lessonDraft = {
					id: lesson.id,
					lessonDate: lesson.lessonDate,
					title: lesson.title,
					description: lesson.description,
				}
			},
		},
	},
	async mounted() {
		await this.loadBootstrap()
	},
	methods: {
		async loadBootstrap() {
			const bootstrap = await fetchBootstrap()
			this.courses = bootstrap.courses || []
			this.settingsDraft = {
				sftpUsername: bootstrap.settings?.sftpUsername || '',
				sftpPassword: bootstrap.settings?.sftpPassword || '',
				publicBaseUrl: bootstrap.settings?.publicBaseUrl || '',
			}
			if (this.courses[0]) {
				this.selectCourse(this.courses[0].id)
			}
		},
		selectCourse(courseId) {
			this.selectedCourseId = courseId
			this.selectedLessonId = this.courses.find((course) => course.id === courseId)?.lessons[0]?.id || null
		},
		selectLesson(lessonId) {
			this.selectedLessonId = lessonId
		},
		handleShowDetailsUpdate(showDetails) {
			if (!showDetails) {
				this.selectedLessonId = null
			}
		},
		openCourseSettings(course) {
			this.courseDraft = {
				id: course.id,
				name: course.name,
				description: course.description || '',
			}
			this.courseModalOpen = true
		},
		openCreateCourseModal() {
			this.courseDraft = {
				id: null,
				name: '',
				description: '',
			}
			this.courseModalOpen = true
		},
		closeCourseModal() {
			this.courseModalOpen = false
		},
		async renameCourse(course, name) {
			try {
				const updated = await updateCourse(course.id, {
					name,
					description: course.description || '',
				})
				this.upsertCourse(updated)
			} catch (error) {
				showError('Kurs konnte nicht umbenannt werden.')
			}
		},
		async handleCreateCourse() {
			try {
				const course = await createCourse(this.courseDraft)
				this.courses.push(course)
				this.selectCourse(course.id)
				this.closeCourseModal()
				showSuccess('Kurs angelegt.')
			} catch (error) {
				showError('Kurs konnte nicht angelegt werden.')
			}
		},
		async saveCourse() {
			try {
				const course = await updateCourse(this.courseDraft.id, this.courseDraft)
				this.upsertCourse(course)
				this.closeCourseModal()
				showSuccess('Kurs gespeichert.')
			} catch (error) {
				showError('Kurs konnte nicht gespeichert werden.')
			}
		},
		async submitCourseModal() {
			if (!this.courseDraft.name.trim()) {
				showError('Bitte einen Kursnamen eingeben.')
				return
			}

			if (this.courseDraft.id) {
				await this.saveCourse()
				return
			}

			await this.handleCreateCourse()
		},
		async handleCreateLesson() {
			if (!this.selectedCourse) {
				return
			}

			try {
				const lesson = await createLesson(this.selectedCourse.id, {
					lessonDate: new Date().toISOString().slice(0, 10),
					title: 'Neue Stunde',
					description: '',
				})
				this.selectedCourse.lessons.push(lesson)
				this.selectLesson(lesson.id)
			} catch (error) {
				showError('Stunde konnte nicht angelegt werden.')
			}
		},
		async saveLesson() {
			if (!this.lessonDraft.id) {
				return
			}

			try {
				const lesson = await updateLesson(this.lessonDraft.id, this.lessonDraft)
				this.replaceLesson(lesson)
				showSuccess('Stunde gespeichert.')
			} catch (error) {
				showError('Stunde konnte nicht gespeichert werden.')
			}
		},
		async removeLesson() {
			if (!this.selectedLesson || !this.selectedCourse) {
				return
			}

			try {
				const lessonId = this.selectedLesson.id
				await deleteLesson(lessonId)
				this.selectedCourse.lessons = this.selectedCourse.lessons.filter((lesson) => lesson.id !== lessonId)
				this.selectedLessonId = this.selectedCourse.lessons[0]?.id || null
				showSuccess('Stunde geloescht.')
			} catch (error) {
				showError('Stunde konnte nicht geloescht werden.')
			}
		},
		async handleCreateItem() {
			if (!this.selectedLesson) {
				return
			}

			try {
				const item = await createLessonItem(this.selectedLesson.id, {
					title: 'Neues Element',
					description: '',
					published: false,
				})
				this.selectedLesson.items.push(item)
			} catch (error) {
				showError('Element konnte nicht angelegt werden.')
			}
		},
		async saveItem(item) {
			try {
				const updated = await updateLessonItem(item.id, item)
				this.replaceItem(updated)
				showSuccess(updated.published ? 'Element gespeichert und publiziert.' : 'Element gespeichert.')
			} catch (error) {
				showError('Element konnte nicht gespeichert werden.')
			}
		},
		async toggleItemPublished(item, published) {
			item.published = published
			await this.saveItem(item)
		},
		isFirstItem(item) {
			return this.selectedLesson?.items.findIndex((entry) => entry.id === item.id) === 0
		},
		isLastItem(item) {
			const items = this.selectedLesson?.items || []
			return items.findIndex((entry) => entry.id === item.id) === items.length - 1
		},
		async moveItem(item, direction) {
			if (!this.selectedLesson) {
				return
			}

			const items = [...this.selectedLesson.items].sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
			const currentIndex = items.findIndex((entry) => entry.id === item.id)
			const targetIndex = currentIndex + direction
			if (currentIndex === -1 || targetIndex < 0 || targetIndex >= items.length) {
				return
			}

			const targetItem = items[targetIndex]
			const currentSortOrder = item.sortOrder
			item.sortOrder = targetItem.sortOrder
			targetItem.sortOrder = currentSortOrder

			try {
				const [updatedCurrent, updatedTarget] = await Promise.all([
					updateLessonItem(item.id, item),
					updateLessonItem(targetItem.id, targetItem),
				])
				this.replaceItem(updatedCurrent)
				this.replaceItem(updatedTarget)
				this.selectedLesson.items.sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
			} catch (error) {
				showError('Reihenfolge konnte nicht geaendert werden.')
			}
		},
		openAttachmentPicker(itemId) {
			document.getElementById(`attachment-input-${itemId}`)?.click()
		},
		async handleAttachmentSelected(item, event) {
			const file = event?.target?.files?.[0]
			if (!file) {
				return
			}

			try {
				const attachment = await uploadAttachment(item.id, file)
				if (!Array.isArray(item.attachments)) {
					item.attachments = []
				}
				item.attachments.push(attachment)
				showSuccess('Datei hochgeladen.')
			} catch (error) {
				showError('Datei konnte nicht hochgeladen werden.')
			} finally {
				event.target.value = ''
			}
		},
		async persistSettings() {
			try {
				const normalizedUrl = this.normalizeBaseUrl(this.settingsDraft.publicBaseUrl)
				this.settingsDraft = await saveSettings({
					sftpHost: this.extractHost(normalizedUrl),
					sftpUsername: this.settingsDraft.sftpUsername,
					sftpPassword: this.settingsDraft.sftpPassword,
					publicBaseUrl: normalizedUrl,
				})
				showSuccess('Einstellungen gespeichert.')
			} catch (error) {
				showError('Einstellungen konnten nicht gespeichert werden.')
			}
		},
		async handlePublishCourse() {
			if (!this.selectedCourse) {
				return
			}

			try {
				const response = await publishCourse(this.selectedCourse.id)
				if (!response.ok) {
					showError(response.message || 'Publishing fehlgeschlagen.')
					return
				}

				this.upsertCourse(response.course)
				showSuccess(`Kurs publiziert: ${response.publicUrl}`)
			} catch (error) {
				showError('Publishing fehlgeschlagen.')
			}
		},
		upsertCourse(course) {
			const index = this.courses.findIndex((entry) => entry.id === course.id)
			if (index === -1) {
				this.courses.push(course)
			} else {
				this.courses.splice(index, 1, course)
			}
			this.selectCourse(course.id)
		},
		replaceLesson(lesson) {
			const index = this.selectedCourse.lessons.findIndex((entry) => entry.id === lesson.id)
			if (index !== -1) {
				this.selectedCourse.lessons.splice(index, 1, {
					...this.selectedCourse.lessons[index],
					...lesson,
				})
			}
			this.selectLesson(lesson.id)
		},
		replaceItem(item) {
			const index = this.selectedLesson.items.findIndex((entry) => entry.id === item.id)
			if (index !== -1) {
				this.selectedLesson.items.splice(index, 1, item)
			}
		},
		formatDate(value) {
			if (!value) {
				return ''
			}
			return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`))
		},
		normalizeBaseUrl(value) {
			const trimmed = value.trim()
			if (!trimmed) {
				return ''
			}
			try {
				const url = new URL(trimmed)
				return url.toString().replace(/\/+$/, '')
			} catch (error) {
				return trimmed.replace(/\/+$/, '')
			}
		},
		extractHost(value) {
			try {
				return new URL(value).hostname
			} catch (error) {
				return value
			}
		},
	},
}
</script>

<style lang="scss">
.settings-panel,
.list-panel,
.details-panel,
.dialog-body,
.item-form {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.list-panel,
.details-panel {
	padding: 1rem;
}

.list-panel {
	padding-top: 4.5rem;
}

.list-panel__header,
.details-panel__header,
.details-panel__actions,
.item-form__header,
.item-form__actions,
.dialog-actions,
.list-panel__actions {
	display: flex;
	gap: 0.75rem;
}

.list-panel__header,
.details-panel__header,
.item-form__header {
	align-items: flex-start;
	justify-content: space-between;
}

.details-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 1rem;
}

.list-panel__header {
	flex-direction: column;
	align-items: flex-start;
	justify-content: flex-start;
}

.list-panel__header h2,
.list-panel__header p {
	margin: 0;
}

.list-panel__divider {
	width: 100%;
	height: 1px;
	background: var(--color-border);
}

.list-panel__actions {
	align-items: center;
	flex-wrap: wrap;
}

.lesson-list,
.item-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.item-form__main {
	flex: 1 1 auto;
	min-width: 0;
	width: 100%;
}

.item-form__controls {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.75rem;
	flex: 0 0 auto;
}

.item-form__order-buttons {
	display: flex;
	gap: 0.5rem;
}

.item-form__textarea {
	min-height: 24rem;
	width: 100%;
}

.item-form__attachments {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.item-form__attachments-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
}

.item-form__attachment-list {
	margin: 0;
	padding-left: 1.25rem;
}

.item-form__file-input {
	display: none;
}

.lesson-entry {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.details-panel__header--sub {
	margin-top: 1rem;
}

.item-form__actions,
.dialog-actions {
	justify-content: flex-end;
}

.dialog-body {
	padding: 1.5rem;
}

.dialog-header {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	margin-bottom: 0.5rem;
}

.dialog-header h2,
.dialog-header p {
	margin: 0;
}

.dialog-actions {
	margin-top: 0.75rem;
	padding-top: 0.5rem;
}

@media (max-width: 1024px) {
	.details-grid {
		grid-template-columns: 1fr;
	}

	.list-panel__header,
	.details-panel__header,
	.item-form__header,
	.list-panel__actions {
		flex-direction: column;
	}
}
</style>
