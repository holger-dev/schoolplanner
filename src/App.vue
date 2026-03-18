<template>
	<NcContent app-name="schoolplanner">
		<div v-if="publishInProgress" class="publish-overlay" aria-live="polite" aria-busy="true">
			<div class="publish-overlay__card">
				<NcLoadingIcon :size="48" />
				<strong>Publishing läuft…</strong>
				<span>Die veröffentlichten Inhalte werden gerade aktualisiert.</span>
			</div>
		</div>

		<NcAppNavigation aria-label="Kurse">
			<template #list>
				<NcButton class="nav-action-button" wide @click="openBlockPlannerModal">Blockansicht</NcButton>
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
			:list-size="22"
			:list-min-width="18"
			:list-max-width="24"
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
							<h2>{{ selectedCourse ? selectedCourse.name : 'Kein Kurs ausgewählt' }}</h2>
							<p>{{ selectedCourse ? selectedCourse.description || 'Noch keine Kursbeschreibung.' : 'Bitte links einen Kurs anlegen oder auswählen.' }}</p>
						</div>
					</div>

					<div v-if="selectedCourse" class="list-panel__divider" />

					<div class="list-panel__actions" v-if="selectedCourse">
						<NcButton type="primary" @click="handleCreateLesson">Neue Stunde</NcButton>
						<NcButton @click="openLessonSeriesModal">Mehrere Stunden anlegen</NcButton>
						<NcButton @click="openCopyLessonModal">Stunde kopieren</NcButton>
						<NcButton @click="handlePublishCourse">Makroplanung veröffentlichen</NcButton>
						<NcButton @click="confirmRemoveCourse">Kurs löschen</NcButton>
					</div>

						<NcEmptyContent
							v-if="!selectedCourse"
							name="Noch kein Kurs"
							description="Lege links einen Kurs an oder wähle einen vorhandenen Kurs aus." />

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
										<div class="lesson-entry__meta">
											<span>{{ formatDate(lesson.lessonDate) }}</span>
											<span>{{ lesson.lessonSlot }}. Std.</span>
											<span>{{ truncateText(lesson.goal || 'Noch kein Ziel', 30) }}</span>
										</div>
									</div>
								</template>
							</NcAppNavigationItem>
						</div>
					</div>
				</NcAppContentList>
			</template>

			<NcAppContentDetails>
				<div v-if="selectedLesson" class="details-panel">
					<div class="details-panel__modebar">
						<NcButton @click="openLiveModeModal">Live-Modus</NcButton>
					</div>
					<div class="details-panel__header">
						<div>
							<h2>Stunde</h2>
							<p>Datum, Thema und Beschreibung der Unterrichtseinheit.</p>
						</div>
						<div class="details-panel__actions">
							<NcButton @click="confirmRemoveLesson">Stunde löschen</NcButton>
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
							v-model="lessonDraft.lessonSlot"
							label="Stundenplanslot"
							type="number"
							min="1"
							max="8" />
						<NcTextField
							v-model="lessonDraft.title"
							label="Thema"
							placeholder="z. B. Einfuehrung Python" />
					</div>

					<NcTextField
						v-model="lessonDraft.goal"
						label="Ziel der Stunde"
						placeholder="z. B. SuS verstehen Variablen und erste Python-Skripte" />

					<NcNoteCard v-if="previousLessonReflection" type="warning">
						<div class="lesson-reflection-preview">
							<strong>Fazit aus der letzten Stunde</strong>
							<p>{{ previousLessonReflection }}</p>
						</div>
					</NcNoteCard>

					<NcTextArea
						v-model="lessonDraft.description"
						label="Beschreibung (Markdown)"
						resize="vertical" />

					<div class="details-panel__header details-panel__header--sub">
						<div>
							<h2>Stundenablauf</h2>
							<p>Elemente können einzeln veröffentlicht werden.</p>
						</div>
						<NcButton @click="handleCreateItem">Element anlegen</NcButton>
					</div>

					<NcEmptyContent
						v-if="selectedLesson.items.length === 0"
						name="Noch keine Ablauf-Elemente"
						description="Lege das erste Element für diese Stunde an." />

					<div v-else class="item-list">
						<NcNoteCard
							v-for="item in selectedLesson.items"
							:key="item.id"
							class="item-card"
							type="info">
							<div class="item-form">
								<div class="item-form__toolbar">
									<div class="item-form__main">
										<NcTextField
											v-model="item.title"
											label="Titel"
											placeholder="Titel des Elements"
											@update:model-value="scheduleItemAutosave(item)" />
									</div>
									<div class="item-form__toolbar-actions">
										<NcButton
											aria-label="Element löschen"
											title="Element löschen"
											variant="tertiary"
											@click="confirmRemoveItem(item)">
											<template #icon>
												<svg viewBox="0 0 24 24" aria-hidden="true" class="item-form__icon">
													<path :d="icons.delete" />
												</svg>
											</template>
										</NcButton>
										<NcButton
											:disabled="isFirstItem(item)"
											aria-label="Nach oben"
											title="Nach oben"
											variant="tertiary"
											@click="moveItem(item, -1)">
											<template #icon>
												<svg viewBox="0 0 24 24" aria-hidden="true" class="item-form__icon">
													<path :d="icons.arrowUp" />
												</svg>
											</template>
										</NcButton>
										<NcButton
											:disabled="isLastItem(item)"
											aria-label="Nach unten"
											title="Nach unten"
											variant="tertiary"
											@click="moveItem(item, 1)">
											<template #icon>
												<svg viewBox="0 0 24 24" aria-hidden="true" class="item-form__icon">
													<path :d="icons.arrowDown" />
												</svg>
											</template>
										</NcButton>
										<NcButton
											aria-label="Element speichern"
											title="Element speichern"
											variant="tertiary"
											@click="saveItem(item)">
											<template #icon>
												<svg viewBox="0 0 24 24" aria-hidden="true" class="item-form__icon">
													<path :d="icons.save" />
												</svg>
											</template>
										</NcButton>
										<NcCheckboxRadioSwitch
											class="item-form__current-toggle"
											:model-value="item.isCurrent"
											type="checkbox"
											@update:model-value="toggleItemCurrent(item, $event)">
											Aktuell
										</NcCheckboxRadioSwitch>
										<NcCheckboxRadioSwitch
											class="item-form__publish-toggle"
											:model-value="item.published"
											type="switch"
											@update:model-value="toggleItemPublished(item, $event)">
											Veröffentlichen
										</NcCheckboxRadioSwitch>
									</div>
								</div>

								<NcTextArea
									v-model="item.description"
									class="item-form__description"
									label="Beschreibung (Markdown)"
									rows="12"
									input-class="item-form__textarea"
									resize="vertical"
									@update:model-value="scheduleItemAutosave(item)" />

								<NcTextField
									v-model="item.teacherNote"
									label="Hinweise für Lehrer:in"
									@update:model-value="scheduleItemAutosave(item)" />

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
							</div>
						</NcNoteCard>

						<div class="item-list__footer">
							<NcButton @click="handleCreateItem">Element hinzufügen</NcButton>
						</div>
					</div>

					<NcTextArea
						v-model="lessonDraft.reflection"
						label="Fazit der Stunde"
						helper-text="Dieses Feld ist nur intern und wird nicht veröffentlicht."
						resize="vertical"
						@update:model-value="scheduleLessonReflectionAutosave" />
				</div>

				<NcEmptyContent
					v-else
					name="Keine Stunde ausgewählt"
					description="Wähle links eine Stunde aus, um den Ablauf zu bearbeiten." />
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

		<NcModal v-if="confirmModalOpen" size="normal" :name="confirmDialog.title" @close="closeConfirmModal">
			<div class="dialog-body">
				<div class="dialog-header">
					<h2>{{ confirmDialog.title }}</h2>
					<p>{{ confirmDialog.message }}</p>
				</div>
				<div class="dialog-actions">
					<NcButton @click="closeConfirmModal">Abbrechen</NcButton>
					<NcButton type="primary" @click="performConfirmedAction">Löschen</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="copyLessonModalOpen" size="normal" name="Stunde kopieren" @close="closeCopyLessonModal">
			<div class="dialog-body">
				<div class="dialog-header">
					<h2>Stunde kopieren</h2>
					<p>Wähle zuerst einen Kurs und dann die Stunde, die in den aktuell ausgewählten Kurs kopiert werden soll.</p>
				</div>
				<NcSelect
					v-model="copyLessonDraft.sourceCourse"
					:options="copySourceCourseOptions"
					input-label="Quellkurs"
					label="label"
					track-by="value"
					placeholder="Kurs auswählen" />
				<NcSelect
					v-model="copyLessonDraft.sourceLesson"
					:options="copySourceLessonOptions"
					input-label="Quellstunde"
					label="label"
					track-by="value"
					placeholder="Stunde auswählen"
					:disabled="copySourceLessonOptions.length === 0" />
				<div class="dialog-actions">
					<NcButton @click="closeCopyLessonModal">Abbrechen</NcButton>
					<NcButton type="primary" :disabled="!copyLessonDraft.sourceLesson" @click="submitCopyLesson">Stunde kopieren</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="lessonSeriesModalOpen" size="normal" name="Mehrere Stunden anlegen" @close="closeLessonSeriesModal">
			<div class="dialog-body">
				<div class="dialog-header">
					<h2>Mehrere Stunden anlegen</h2>
					<p>Die Stunden werden ab dem Startdatum im Wochenrhythmus angelegt und erhalten automatisch den Titel `ToDo`.</p>
				</div>
				<div class="details-grid">
					<NcDateTimePickerNative
						:model-value="lessonSeriesDraftDate"
						label="Startdatum"
						type="date"
						@update:model-value="lessonSeriesDraftDate = $event" />
					<NcTextField
						v-model="lessonSeriesDraft.lessonSlot"
						label="Stundenplanslot"
						type="number"
						min="1"
						max="8" />
					<NcTextField
						v-model="lessonSeriesDraft.count"
						label="Anzahl"
						type="number"
						min="1"
						max="52" />
				</div>
				<div class="dialog-actions">
					<NcButton @click="closeLessonSeriesModal">Abbrechen</NcButton>
					<NcButton type="primary" @click="submitLessonSeries">Stunden anlegen</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="blockPlannerModalOpen" size="full" name="Blockansicht" @close="closeBlockPlannerModal">
			<div class="dialog-body block-planner-modal">
				<div class="dialog-header block-planner-header">
					<div>
						<h2>Blockansicht</h2>
						<p>{{ blockPlannerWeekLabel }}</p>
					</div>
					<div class="dialog-actions">
						<NcButton @click="shiftBlockPlannerWeek(-1)">Zurück</NcButton>
						<NcButton @click="jumpToCurrentBlockWeek">Aktuelle Woche</NcButton>
						<NcButton @click="shiftBlockPlannerWeek(1)">Vor</NcButton>
					</div>
				</div>

				<div class="block-grid">
					<div class="block-grid__corner">Stunde</div>
					<div v-for="day in blockPlannerDays" :key="day.key" class="block-grid__day">
						<strong>{{ day.label }}</strong>
						<span>{{ day.dateLabel }}</span>
					</div>

					<template v-for="row in blockPlannerRows" :key="`slot-${row.slot}`">
						<div class="block-grid__slot">{{ row.slot }}. Std.</div>
						<div
							v-for="day in row.days"
							:key="`${row.slot}-${day.key}`"
							class="block-grid__cell">
							<div v-if="day.entries.length" class="block-grid__entries">
								<button
									v-for="entry in day.entries"
									:key="entry.lessonId"
									class="block-entry"
									type="button"
									@click="openLessonFromBlockPlanner(entry.courseId, entry.lessonId)">
									<div class="block-entry__top">
										<strong>{{ entry.courseName }}</strong>
										<span v-if="entry.isEmpty" class="block-entry__warning">!</span>
										<span v-else class="block-entry__count">{{ entry.itemCount }}</span>
									</div>
									<div class="block-entry__title">{{ entry.title }}</div>
								</button>
							</div>
						</div>
					</template>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="liveModeModalOpen" size="large" name="Live-Modus" @close="closeLiveModeModal">
			<div class="dialog-body live-mode-modal">
				<div class="dialog-header">
					<h2>Live-Modus</h2>
					<p v-if="selectedLesson">{{ selectedLesson.title }}</p>
				</div>

				<NcEmptyContent
					v-if="!selectedLesson || sortedLessonItems.length === 0"
					name="Keine Elemente vorhanden"
					description="Lege zuerst mindestens ein Element für diese Stunde an." />

				<template v-else-if="!liveCurrentItem">
					<div class="live-mode__intro">
						<p>Mit erstem Element die Stunde starten?</p>
					</div>
					<div class="dialog-actions">
						<NcButton @click="closeLiveModeModal">Schließen</NcButton>
						<NcButton type="primary" :disabled="liveModeInProgress" @click="startLiveMode">Ja</NcButton>
					</div>
				</template>

				<template v-else>
					<div class="live-mode__stage">
						<section class="live-mode__card live-mode__card--current">
							<span class="live-mode__label">Aktuelles Element</span>
							<h3>{{ liveCurrentItem.title }}</h3>
							<p>{{ truncateText(stripMarkdown(liveCurrentItem.description), 180) || 'Kein Beschreibungstext.' }}</p>
						</section>

						<div class="live-mode__controls">
							<NcButton
								type="primary"
								:disabled="!liveNextItem || liveModeInProgress"
								@click="advanceLiveMode">
								{{ liveNextItem ? 'Weiter' : 'Stunde beendet' }}
							</NcButton>
						</div>

						<section class="live-mode__card live-mode__card--next">
							<span class="live-mode__label">Nächstes Element</span>
							<template v-if="liveNextItem">
								<h3>{{ liveNextItem.title }}</h3>
								<p>{{ truncateText(stripMarkdown(liveNextItem.description), 180) || 'Kein Beschreibungstext.' }}</p>
							</template>
							<template v-else>
								<h3>Kein weiteres Element</h3>
								<p>Das aktuelle Element ist bereits das letzte in dieser Stunde.</p>
							</template>
						</section>
					</div>
					<div class="dialog-actions">
						<NcButton @click="closeLiveModeModal">Schließen</NcButton>
					</div>
				</template>
			</div>
		</NcModal>
	</NcContent>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mdiArrowDown, mdiArrowUp, mdiContentSave, mdiDelete } from '@mdi/js'
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
	NcEmptyContent,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	copyLesson,
	createCourse,
	createLesson,
	createLessonItem,
	deleteCourse,
	deleteLesson,
	deleteLessonItem,
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
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
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
			confirmModalOpen: false,
			confirmDialog: {
				action: null,
				title: '',
				message: '',
				itemId: null,
			},
			copyLessonModalOpen: false,
			copyLessonDraft: {
				sourceCourse: null,
				sourceLesson: null,
			},
			lessonSeriesModalOpen: false,
			lessonSeriesDraft: {
				lessonDate: '',
				lessonSlot: '1',
				count: '4',
				title: 'Neue Stunde',
				goal: '',
				description: '',
			},
			blockPlannerModalOpen: false,
			blockPlannerWeekStart: '',
			liveModeModalOpen: false,
			liveModeInProgress: false,
			settingsDraft: {
				sftpUsername: '',
				sftpPassword: '',
				publicBaseUrl: '',
			},
			publishInProgress: false,
			itemAutosaveTimers: {},
			lessonReflectionAutosaveTimer: null,
			lessonDraft: {
				id: null,
				lessonDate: '',
				lessonSlot: '1',
				title: '',
				goal: '',
				description: '',
				reflection: '',
			},
			icons: {
				arrowUp: mdiArrowUp,
				arrowDown: mdiArrowDown,
				save: mdiContentSave,
				delete: mdiDelete,
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
		sortedLessonItems() {
			return [...(this.selectedLesson?.items || [])].sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
		},
		liveCurrentIndex() {
			return this.sortedLessonItems.findIndex((item) => item.published && item.isCurrent)
		},
		liveCurrentItem() {
			return this.liveCurrentIndex >= 0 ? this.sortedLessonItems[this.liveCurrentIndex] : null
		},
		liveNextItem() {
			return this.liveCurrentIndex >= 0 ? this.sortedLessonItems[this.liveCurrentIndex + 1] || null : null
		},
		sortedLessons() {
			return [...(this.selectedCourse?.lessons || [])].sort((a, b) => {
				const dateCompare = a.lessonDate.localeCompare(b.lessonDate)
				if (dateCompare !== 0) {
					return dateCompare
				}
				return (a.lessonSlot || 1) - (b.lessonSlot || 1)
			})
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
		lessonSeriesDraftDate: {
			get() {
				return this.lessonSeriesDraft.lessonDate ? new Date(`${this.lessonSeriesDraft.lessonDate}T00:00:00`) : null
			},
			set(value) {
				this.lessonSeriesDraft.lessonDate = value instanceof Date
					? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
					: ''
			},
		},
		previousLessonReflection() {
			if (!this.selectedLesson) {
				return ''
			}
			const lessons = this.sortedLessons
			const currentIndex = lessons.findIndex((lesson) => lesson.id === this.selectedLesson.id)
			if (currentIndex <= 0) {
				return ''
			}
			return lessons[currentIndex - 1]?.reflection || ''
		},
		copySourceCourseOptions() {
			return this.courses
				.filter((course) => (course.lessons || []).length > 0)
				.map((course) => ({
					label: course.name,
					value: course.id,
				}))
		},
		copySourceLessonOptions() {
			const sourceCourseId = this.copyLessonDraft.sourceCourse?.value
			const sourceCourse = this.courses.find((course) => course.id === sourceCourseId)
			return (sourceCourse?.lessons || []).map((lesson) => ({
				label: `${this.formatDate(lesson.lessonDate)} · ${lesson.lessonSlot || 1}. Std. · ${lesson.title}`,
				value: lesson.id,
			}))
		},
		blockPlannerDays() {
			const start = this.blockPlannerWeekStartDate
			return Array.from({ length: 5 }, (_, index) => {
				const current = new Date(start)
				current.setDate(start.getDate() + index)
				return {
					key: this.toDateKey(current),
					label: ['Mo', 'Di', 'Mi', 'Do', 'Fr'][index],
					dateLabel: this.formatDateKey(this.toDateKey(current)),
				}
			})
		},
		blockPlannerWeekStartDate() {
			return this.blockPlannerWeekStart ? new Date(`${this.blockPlannerWeekStart}T00:00:00`) : this.startOfWeek(new Date())
		},
		blockPlannerWeekLabel() {
			const start = this.blockPlannerWeekStartDate
			const end = new Date(start)
			end.setDate(start.getDate() + 4)
			return `${this.formatDateKey(this.toDateKey(start))} bis ${this.formatDateKey(this.toDateKey(end))}`
		},
		blockPlannerRows() {
			return Array.from({ length: 8 }, (_, index) => {
				const slot = index + 1
				return {
					slot,
					days: this.blockPlannerDays.map((day) => ({
						...day,
						entries: this.getBlockPlannerEntries(day.key, slot),
					})),
				}
			})
		},
	},
	watch: {
		'copyLessonDraft.sourceCourse'(course) {
			const lessonExists = this.copySourceLessonOptions.some((lesson) => lesson.value === this.copyLessonDraft.sourceLesson?.value)
			if (!course || !lessonExists) {
				this.copyLessonDraft.sourceLesson = null
			}
		},
		selectedLesson: {
			immediate: true,
			handler(lesson) {
				if (this.lessonReflectionAutosaveTimer) {
					window.clearTimeout(this.lessonReflectionAutosaveTimer)
					this.lessonReflectionAutosaveTimer = null
				}
				if (!lesson) {
					this.lessonDraft = { id: null, lessonDate: '', lessonSlot: '1', title: '', goal: '', description: '', reflection: '' }
					return
				}

				this.lessonDraft = {
					id: lesson.id,
					lessonDate: lesson.lessonDate,
					lessonSlot: String(lesson.lessonSlot || 1),
					title: lesson.title,
					goal: lesson.goal || '',
					description: lesson.description,
					reflection: lesson.reflection || '',
				}
			},
		},
	},
	async mounted() {
		await this.loadBootstrap()
	},
	beforeUnmount() {
		Object.values(this.itemAutosaveTimers).forEach((timerId) => {
			window.clearTimeout(timerId)
		})
		if (this.lessonReflectionAutosaveTimer) {
			window.clearTimeout(this.lessonReflectionAutosaveTimer)
		}
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
			this.jumpToCurrentBlockWeek()
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
		openCopyLessonModal() {
			this.copyLessonDraft = {
				sourceCourse: this.copySourceCourseOptions[0] || null,
				sourceLesson: null,
			}
			this.copyLessonModalOpen = true
		},
		openLessonSeriesModal() {
			this.lessonSeriesDraft = {
				lessonDate: new Date().toISOString().slice(0, 10),
				lessonSlot: '1',
				count: '4',
				title: 'ToDo',
				goal: '',
				description: '',
			}
			this.lessonSeriesModalOpen = true
		},
		openBlockPlannerModal() {
			this.blockPlannerModalOpen = true
		},
		openLiveModeModal() {
			this.liveModeModalOpen = true
		},
		closeCourseModal() {
			this.courseModalOpen = false
		},
		closeCopyLessonModal() {
			this.copyLessonModalOpen = false
			this.copyLessonDraft = {
				sourceCourse: null,
				sourceLesson: null,
			}
		},
		closeLessonSeriesModal() {
			this.lessonSeriesModalOpen = false
		},
		closeBlockPlannerModal() {
			this.blockPlannerModalOpen = false
		},
		closeLiveModeModal() {
			this.liveModeModalOpen = false
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
		confirmRemoveCourse() {
			if (!this.selectedCourse) {
				return
			}
			this.confirmDialog = {
				action: 'course',
				title: 'Kurs löschen',
				message: `Möchtest du den Kurs "${this.selectedCourse.name}" wirklich löschen?`,
				itemId: this.selectedCourse.id,
			}
			this.confirmModalOpen = true
		},
		async removeCourse(courseId = this.selectedCourse?.id) {
			if (!courseId) {
				return
			}

			try {
				await deleteCourse(courseId)
				this.courses = this.courses.filter((course) => course.id !== courseId)
				const nextCourse = this.courses[0] || null
				this.selectedCourseId = nextCourse?.id || null
				this.selectedLessonId = nextCourse?.lessons?.[0]?.id || null
				showSuccess('Kurs gelöscht.')
			} catch (error) {
				showError('Kurs konnte nicht gelöscht werden.')
			}
		},
		confirmRemoveLesson() {
			if (!this.selectedLesson) {
				return
			}
			this.confirmDialog = {
				action: 'lesson',
				title: 'Stunde löschen',
				message: `Möchtest du die Stunde "${this.selectedLesson.title}" wirklich löschen?`,
				itemId: this.selectedLesson.id,
			}
			this.confirmModalOpen = true
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
					lessonSlot: 1,
					title: 'Neue Stunde',
					goal: '',
					description: '',
					reflection: '',
				})
				this.selectedCourse.lessons.push(lesson)
				this.selectLesson(lesson.id)
			} catch (error) {
				showError('Stunde konnte nicht angelegt werden.')
			}
		},
		async submitCopyLesson() {
			if (!this.selectedCourse || !this.copyLessonDraft.sourceLesson?.value) {
				return
			}

			try {
				const lesson = await copyLesson(this.selectedCourse.id, this.copyLessonDraft.sourceLesson.value)
				this.selectedCourse.lessons.push(lesson)
				this.selectLesson(lesson.id)
				this.closeCopyLessonModal()
				showSuccess('Stunde kopiert.')
			} catch (error) {
				showError('Stunde konnte nicht kopiert werden.')
			}
		},
		async submitLessonSeries() {
			if (!this.selectedCourse) {
				return
			}

			try {
				const count = Math.max(1, Math.min(52, Number.parseInt(this.lessonSeriesDraft.count, 10) || 1))
				const slot = this.normalizeLessonSlot(this.lessonSeriesDraft.lessonSlot)
				const startDate = this.lessonSeriesDraft.lessonDate || this.toDateKey(new Date())
				const lessons = []

				for (let index = 0; index < count; index += 1) {
					const lesson = await createLesson(this.selectedCourse.id, {
						lessonDate: this.addWeeksToDateKey(startDate, index),
						lessonSlot: slot,
						title: 'ToDo',
						goal: '',
						description: '',
						reflection: '',
					})
					lessons.push(lesson)
				}

				this.selectedCourse.lessons.push(...lessons)
				if (lessons[0]?.lessonDate) {
					this.blockPlannerWeekStart = this.toDateKey(this.startOfWeek(new Date(`${lessons[0].lessonDate}T00:00:00`)))
				}
				if (lessons[0]) {
					this.selectLesson(lessons[0].id)
				}
				this.closeLessonSeriesModal()
				showSuccess(`${lessons.length} Stunden angelegt.`)
			} catch (error) {
				showError('Serienstunden konnten nicht angelegt werden.')
			}
		},
		async saveLesson() {
			if (!this.lessonDraft.id) {
				return
			}

			if (this.lessonReflectionAutosaveTimer) {
				window.clearTimeout(this.lessonReflectionAutosaveTimer)
				this.lessonReflectionAutosaveTimer = null
			}

			try {
				const lesson = await updateLesson(this.lessonDraft.id, {
					...this.lessonDraft,
					lessonSlot: this.normalizeLessonSlot(this.lessonDraft.lessonSlot),
				})
				this.replaceLesson(lesson)
				showSuccess('Stunde gespeichert.')
			} catch (error) {
				showError('Stunde konnte nicht gespeichert werden.')
			}
		},
		scheduleLessonReflectionAutosave() {
			if (!this.lessonDraft.id) {
				return
			}
			if (this.lessonReflectionAutosaveTimer) {
				window.clearTimeout(this.lessonReflectionAutosaveTimer)
			}
			this.lessonReflectionAutosaveTimer = window.setTimeout(async () => {
				this.lessonReflectionAutosaveTimer = null
				try {
					const lesson = await updateLesson(this.lessonDraft.id, {
						...this.lessonDraft,
						lessonSlot: this.normalizeLessonSlot(this.lessonDraft.lessonSlot),
					})
					this.replaceLesson(lesson)
				} catch (error) {
					// Keep autosave quiet; the explicit save button still surfaces errors.
				}
			}, 900)
		},
		async removeLesson(lessonId = this.selectedLesson?.id) {
			if (!lessonId || !this.selectedCourse) {
				return
			}

			try {
				await deleteLesson(lessonId)
				this.selectedCourse.lessons = this.selectedCourse.lessons.filter((lesson) => lesson.id !== lessonId)
				this.selectedLessonId = this.selectedCourse.lessons[0]?.id || null
				showSuccess('Stunde gelöscht.')
			} catch (error) {
				showError('Stunde konnte nicht gelöscht werden.')
			}
		},
		confirmRemoveItem(item) {
			this.confirmDialog = {
				action: 'item',
				title: 'Element löschen',
				message: `Möchtest du das Element "${item.title}" wirklich löschen?`,
				itemId: item.id,
			}
			this.confirmModalOpen = true
		},
		async handleCreateItem() {
			if (!this.selectedLesson) {
				return
			}

			try {
				const item = await createLessonItem(this.selectedLesson.id, {
					title: 'Neues Element',
					description: '',
					teacherNote: '',
					published: false,
					isCurrent: false,
				})
				this.selectedLesson.items.push(item)
			} catch (error) {
				showError('Element konnte nicht angelegt werden.')
			}
		},
		scheduleItemAutosave(item) {
			if (!item?.id) {
				return
			}
			if (this.itemAutosaveTimers[item.id]) {
				window.clearTimeout(this.itemAutosaveTimers[item.id])
			}
			this.itemAutosaveTimers[item.id] = window.setTimeout(() => {
				delete this.itemAutosaveTimers[item.id]
				void this.saveItem(item, { silent: true })
			}, 900)
		},
		async saveItem(item, options = {}) {
			const { triggerPublish = false, silent = false } = options
			if (item?.id && this.itemAutosaveTimers[item.id]) {
				window.clearTimeout(this.itemAutosaveTimers[item.id])
				delete this.itemAutosaveTimers[item.id]
			}
			try {
				if (triggerPublish) {
					this.publishInProgress = true
				}
				const updated = await updateLessonItem(item.id, {
					...item,
					triggerPublish,
				})
				this.replaceItem(updated)
				if (!silent) {
					showSuccess(triggerPublish ? 'Element gespeichert und publiziert.' : 'Element gespeichert.')
				}
			} catch (error) {
				if (!silent) {
					showError('Element konnte nicht gespeichert werden.')
				}
			} finally {
				if (triggerPublish) {
					this.publishInProgress = false
				}
			}
		},
		async toggleItemPublished(item, published) {
			item.published = published
			await this.saveItem(item, { triggerPublish: true })
		},
		async toggleItemCurrent(item, isCurrent) {
			const items = this.selectedLesson?.items || []
			items.forEach((entry) => {
				if (entry.id !== item.id && isCurrent) {
					entry.isCurrent = false
				}
			})
			item.isCurrent = isCurrent
			await this.saveItem(item)
		},
		clearItemAutosaveTimer(itemId) {
			if (this.itemAutosaveTimers[itemId]) {
				window.clearTimeout(this.itemAutosaveTimers[itemId])
				delete this.itemAutosaveTimers[itemId]
			}
		},
		async persistItemsAndPublish(items, successMessage) {
			if (!this.selectedCourse) {
				return
			}

			try {
				const currentLessonId = this.selectedLesson?.id || null
				this.publishInProgress = true
				this.liveModeInProgress = true
				for (const item of items) {
					this.clearItemAutosaveTimer(item.id)
					const updated = await updateLessonItem(item.id, {
						...item,
						triggerPublish: false,
					})
					this.replaceItem(updated)
				}
				const response = await publishCourse(this.selectedCourse.id)
				if (!response.ok) {
					throw new Error(response.message || 'Publishing fehlgeschlagen.')
				}
				this.upsertCourse(response.course)
				if (currentLessonId) {
					this.selectLesson(currentLessonId)
				}
				showSuccess(successMessage)
			} catch (error) {
				showError('Live-Modus konnte nicht aktualisiert werden.')
			} finally {
				this.publishInProgress = false
				this.liveModeInProgress = false
			}
		},
		async startLiveMode() {
			if (!this.selectedLesson || this.sortedLessonItems.length === 0) {
				return
			}

			const firstItemId = this.sortedLessonItems[0].id
			const items = this.sortedLessonItems.map((entry) => ({
				...entry,
				published: entry.id === firstItemId,
				isCurrent: entry.id === firstItemId,
			}))
			await this.persistItemsAndPublish(items, 'Live-Modus gestartet.')
		},
		async advanceLiveMode() {
			if (!this.liveCurrentItem || !this.liveNextItem) {
				return
			}

			const nextItemId = this.liveNextItem.id
			const items = this.sortedLessonItems.map((entry) => ({
				...entry,
				published: entry.published || entry.id === nextItemId,
				isCurrent: entry.id === nextItemId,
			}))
			await this.persistItemsAndPublish(items, 'Nächstes Element veröffentlicht.')
		},
		async removeItem(itemId) {
			if (!this.selectedLesson || !itemId) {
				return
			}

			try {
				this.publishInProgress = true
				await deleteLessonItem(itemId)
				this.selectedLesson.items = this.selectedLesson.items.filter((entry) => entry.id !== itemId)
				showSuccess('Element gelöscht und Publishing aktualisiert.')
			} catch (error) {
				showError('Element konnte nicht gelöscht werden.')
			} finally {
				this.publishInProgress = false
			}
		},
		closeConfirmModal() {
			this.confirmModalOpen = false
			this.confirmDialog = {
				action: null,
				title: '',
				message: '',
				itemId: null,
			}
		},
		async performConfirmedAction() {
			const { action, itemId } = this.confirmDialog
			this.closeConfirmModal()
			if (action === 'course' && itemId) {
				await this.removeCourse(itemId)
				return
			}
			if (action === 'lesson' && itemId) {
				await this.removeLesson(itemId)
				return
			}
			if (action === 'item' && itemId) {
				await this.removeItem(itemId)
			}
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

			const reorderedItems = [...items]
			const [movedItem] = reorderedItems.splice(currentIndex, 1)
			reorderedItems.splice(targetIndex, 0, movedItem)
			const payloads = reorderedItems.map((entry, index) => ({
				...entry,
				sortOrder: index,
			}))

			try {
				const updatedItems = []
				for (const payload of payloads) {
					updatedItems.push(await updateLessonItem(payload.id, payload))
				}
				updatedItems.forEach((updatedItem) => this.replaceItem(updatedItem))
				this.selectedLesson.items.sort((a, b) => a.sortOrder - b.sortOrder || a.id - b.id)
			} catch (error) {
				showError('Reihenfolge konnte nicht geändert werden.')
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
				this.publishInProgress = true
				const attachment = await uploadAttachment(item.id, file)
				if (!Array.isArray(item.attachments)) {
					item.attachments = []
				}
				item.attachments.push(attachment)
				showSuccess('Datei hochgeladen.')
			} catch (error) {
				showError('Datei konnte nicht hochgeladen werden.')
			} finally {
				this.publishInProgress = false
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
				this.publishInProgress = true
				const response = await publishCourse(this.selectedCourse.id)
				if (!response.ok) {
					showError(response.message || 'Publishing fehlgeschlagen.')
					return
				}

				this.upsertCourse(response.course)
				showSuccess(`Kurs publiziert: ${response.publicUrl}`)
			} catch (error) {
				showError('Publishing fehlgeschlagen.')
			} finally {
				this.publishInProgress = false
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
		formatDateKey(value) {
			return this.formatDate(value)
		},
		normalizeLessonSlot(value) {
			const numeric = Number.parseInt(value, 10)
			if (Number.isNaN(numeric)) {
				return 1
			}
			return Math.min(8, Math.max(1, numeric))
		},
		startOfWeek(date) {
			const current = new Date(date)
			const day = current.getDay()
			const diff = day === 0 ? -6 : 1 - day
			current.setHours(0, 0, 0, 0)
			current.setDate(current.getDate() + diff)
			return current
		},
		toDateKey(date) {
			return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
		},
		addWeeksToDateKey(dateKey, weeks) {
			const [year, month, day] = dateKey.split('-').map((value) => Number.parseInt(value, 10))
			const current = new Date(year, (month || 1) - 1, day || 1)
			current.setDate(current.getDate() + (weeks * 7))
			return this.toDateKey(current)
		},
		jumpToCurrentBlockWeek() {
			this.blockPlannerWeekStart = this.toDateKey(this.startOfWeek(new Date()))
		},
		shiftBlockPlannerWeek(direction) {
			const start = new Date(`${this.blockPlannerWeekStart}T00:00:00`)
			start.setDate(start.getDate() + direction * 7)
			this.blockPlannerWeekStart = this.toDateKey(start)
		},
		getBlockPlannerEntries(dateKey, slot) {
			return this.courses.flatMap((course) =>
				(course.lessons || [])
					.filter((lesson) => lesson.lessonDate === dateKey && (lesson.lessonSlot || 1) === slot)
					.map((lesson) => ({
						courseId: course.id,
						courseName: course.name,
						lessonId: lesson.id,
						lessonSlot: lesson.lessonSlot || 1,
						title: lesson.title,
						itemCount: lesson.items?.length || 0,
						isEmpty: (lesson.items?.length || 0) === 0,
					}))
			)
		},
		openLessonFromBlockPlanner(courseId, lessonId) {
			this.selectCourse(courseId)
			this.selectLesson(lessonId)
			this.closeBlockPlannerModal()
		},
		truncateText(value, maxLength) {
			if (!value) {
				return ''
			}
			return value.length > maxLength ? `${value.slice(0, maxLength - 1)}…` : value
		},
		stripMarkdown(value) {
			return (value || '')
				.replace(/```[\s\S]*?```/g, ' ')
				.replace(/`([^`]+)`/g, '$1')
				.replace(/!\[[^\]]*]\([^)]*\)/g, ' ')
				.replace(/\[[^\]]+]\([^)]*\)/g, '$1')
				.replace(/[#>*_~-]/g, ' ')
				.replace(/\s+/g, ' ')
				.trim()
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

.publish-overlay {
	position: fixed;
	inset: 0;
	z-index: 5000;
	display: flex;
	align-items: center;
	justify-content: center;
	background: color-mix(in srgb, var(--color-main-background) 58%, transparent);
	backdrop-filter: blur(3px);
}

.publish-overlay__card {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.6rem;
	padding: 1.5rem 1.75rem;
	border-radius: 14px;
	background: var(--color-main-background);
	box-shadow: 0 18px 36px rgba(15, 23, 42, 0.18);
	text-align: center;
}

.publish-overlay__card span {
	color: var(--color-text-maxcontrast);
}

.nav-action-button {
	width: 100%;
	margin-bottom: 0.75rem;
}

.list-panel,
.details-panel {
	padding: 1rem;
}

.details-panel {
	width: 100%;
	max-width: none;
}

.list-panel {
	padding-top: 4.5rem;
}

.list-panel__header,
.details-panel__header,
.details-panel__actions,
.item-form__toolbar,
.dialog-actions,
.list-panel__actions {
	display: flex;
	gap: 0.75rem;
}

.list-panel__header,
.details-panel__header,
.item-form__toolbar {
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

.item-list__footer {
	display: flex;
	justify-content: flex-end;
	padding-top: 0.25rem;
}

.item-card {
	width: 100%;
}

.item-card > div:last-child {
	flex: 1 1 auto;
	min-width: 0;
	width: 100%;
}

.item-form__main {
	flex: 0 1 50%;
	min-width: 0;
	max-width: 50%;
}

.item-form__toolbar {
	align-items: center;
	gap: 1rem;
	justify-content: space-between;
	flex-wrap: nowrap;
}

.item-form__toolbar-actions {
	display: flex;
	align-items: center;
	flex: 0 0 auto;
	flex-wrap: nowrap;
	gap: 0.5rem;
	margin-left: auto;
	white-space: nowrap;
}

.item-form__publish-toggle {
	flex: 0 0 auto;
}

.item-form__current-toggle {
	flex: 0 0 auto;
}

.item-form__textarea {
	min-height: 24rem;
	width: 100%;
}

.item-form__description,
.item-form__description .textarea,
.item-form__description .textarea__main-wrapper,
.item-form__description .textarea__input {
	width: 100%;
	max-width: none;
}

.item-form__icon {
	width: 1.15rem;
	height: 1.15rem;
	fill: currentColor;
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
	gap: 0.35rem;
}

.lesson-entry__title {
	font-size: 1rem;
	line-height: 1.35;
}

.lesson-entry__meta {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
}

.lesson-entry__meta span:last-child {
	overflow: hidden;
	text-overflow: ellipsis;
}

.lesson-reflection-preview {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

.lesson-reflection-preview p {
	margin: 0;
}

.details-panel__modebar {
	margin-bottom: 0.85rem;
}

.details-panel__header--sub {
	margin-top: 1rem;
}

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

.block-planner-modal {
	gap: 1.25rem;
}

.live-mode-modal {
	gap: 1.25rem;
}

.live-mode__intro {
	padding-top: 0.75rem;
}

.live-mode__intro p {
	margin: 0;
	font-size: 1.05rem;
}

.live-mode__stage {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
	gap: 1rem;
	align-items: stretch;
}

.live-mode__card {
	display: flex;
	flex-direction: column;
	gap: 0.55rem;
	min-height: 16rem;
	padding: 1rem 1.1rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 14px;
	background: var(--color-main-background);
}

.live-mode__card--current {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 18%, transparent);
}

.live-mode__card h3,
.live-mode__card p {
	margin: 0;
}

.live-mode__label {
	font-size: 0.8rem;
	font-weight: 700;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
}

.live-mode__controls {
	display: flex;
	align-items: center;
	justify-content: center;
}

.block-planner-header {
	align-items: flex-start;
}

.block-grid {
	display: grid;
	grid-template-columns: 96px repeat(5, minmax(0, 1fr));
	gap: 0.75rem;
}

.block-grid__corner,
.block-grid__day,
.block-grid__slot,
.block-grid__cell {
	border: 1px solid var(--color-border);
	border-radius: 12px;
	padding: 0.75rem;
	background: var(--color-main-background);
}

.block-grid__corner,
.block-grid__slot {
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 700;
}

.block-grid__day {
	display: flex;
	flex-direction: column;
	gap: 0.2rem;
}

.block-grid__day span {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.block-grid__cell {
	min-height: 8rem;
}

.block-grid__entries {
	display: flex;
	flex-direction: column;
	gap: 0.6rem;
}

.block-entry {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
	width: 100%;
	padding: 0.75rem;
	border: 1px solid var(--color-border-dark);
	border-radius: 10px;
	background: var(--color-background-hover);
	text-align: left;
	cursor: pointer;
}

.block-entry__top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
}

.block-entry__meta {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
}

.block-entry__title {
	font-weight: 600;
	line-height: 1.35;
}

.block-entry__warning,
.block-entry__count {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 1.5rem;
	height: 1.5rem;
	border-radius: 999px;
	font-weight: 700;
}

.block-entry__warning {
	min-width: 1.9rem;
	height: 1.9rem;
	background: #ffd43b;
	color: #3d2f00;
	border: 1px solid #f0b100;
	box-shadow: 0 0 0 2px color-mix(in srgb, #ffd43b 28%, transparent);
	font-size: 1rem;
}

.block-entry__count {
	background: color-mix(in srgb, var(--color-primary-element) 18%, var(--color-main-background));
	color: var(--color-main-text);
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 40%, var(--color-border-dark));
}

@media (max-width: 1024px) {
	.details-grid {
		grid-template-columns: 1fr;
	}

	.list-panel__header,
	.details-panel__header,
	.item-form__toolbar,
	.list-panel__actions {
		flex-direction: column;
	}

	.item-form__main {
		max-width: 100%;
		width: 100%;
	}

	.item-form__toolbar-actions {
		width: 100%;
		justify-content: flex-end;
		flex-wrap: wrap;
		white-space: normal;
	}

	.item-form__textarea {
		min-height: 16rem;
	}

	.block-grid {
		grid-template-columns: 1fr;
	}

	.live-mode__stage {
		grid-template-columns: 1fr;
	}
}
</style>
