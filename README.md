# School Planner

School Planner is a Nextcloud app for teachers who want to plan courses, structure lessons and publish selected lesson content for students as a public website.

The app uses Nextcloud Vue components, stores all planning data inside Nextcloud and can publish static course pages to a web server via SFTP.

## Features

- Create, rename and delete courses in the Nextcloud app navigation
- Add lessons with date, lesson slot, topic, goal and markdown-based description
- Add recurring lessons in weekly series
- Copy existing lessons from one course into another
- Build a lesson flow from multiple elements and reorder elements with arrow controls
- Use autosave for lesson reflections and lesson elements
- Store internal-only lesson reflections (`Fazit der Stunde`)
- Store internal-only teacher notes per lesson element (`Hinweise für Lehrer:in`)
- Mark elements as `Veröffentlichen`
- Mark exactly one element per lesson as `Aktuell`
- Use the `Live-Modus` to walk through a lesson step by step and update the public page
- Upload files per lesson element for student downloads
- Open a weekly `Blockansicht` for all courses across Monday to Friday
- Highlight lessons without elements in the block planner
- Export and import selected courses as ZIP archives including lessons, elements, internal notes and attachments
- Publish courses as a public website via SFTP
- Render markdown on the public website, including headings, lists, code blocks, syntax highlighting and copy buttons
- Automatically reload the public page when new content is published

## Screenshots

These images can be used for GitHub and for the Nextcloud app store listing.

### Nextcloud app

![Course overview](images/Bildschirmfoto%202026-03-18%20um%2012.36.20.png)

![Lesson planning](images/Bildschirmfoto%202026-03-18%20um%2012.36.32.png)

### Public website

![Published course page](images/Bildschirmfoto%202026-03-18%20um%2012.36.43.png)

![Published lesson detail](images/Bildschirmfoto%202026-03-18%20um%2012.36.52.png)

## How it works

1. Create a course such as `8a` or `12 EAInf`.
2. Add lessons with date, slot, topic, goal and description.
3. Add lesson elements with markdown, optional attachments and internal teacher notes.
4. Use `Aktuell` to mark the current internal teaching step.
5. Mark elements as `Veröffentlichen` when they should appear on the public website.
6. Use `Live-Modus` during class to advance from one element to the next.
7. Publish the course to the configured web server via SFTP.

Students can then open the published course page, browse lessons and access released files and materials.

## Lesson planning workflow

Each lesson supports both public and internal planning data.

Public lesson fields:

- date
- lesson slot
- topic
- goal
- lesson description
- published lesson elements
- attachments on published elements

Internal-only lesson fields:

- lesson reflection (`Fazit der Stunde`)
- teacher note per element (`Hinweise für Lehrer:in`)

The reflection of one lesson is automatically shown at the top of the following lesson as `Fazit aus der letzten Stunde`.

## Block planner

The app contains a modal `Blockansicht` that shows a weekly timetable-like overview for all courses from Monday to Friday.

Displayed information per block:

- course name
- lesson topic
- warning marker if no lesson elements exist
- otherwise the number of lesson elements

You can move forward and backward by week and jump back to the current week.

## Import and export

The settings area includes `Import` and `Export`.

Export:

- opens a modal where one or more courses can be selected
- downloads a ZIP archive
- includes courses, lessons, elements, internal notes and attachments

Import:

- accepts a ZIP archive created by School Planner
- restores courses, lessons, elements and attachments

Imported and exported internal data includes:

- lesson reflections
- teacher notes
- publish state
- current-item state
- lesson slots
- element order

## Publishing

The app publishes a static website via SFTP.

Required settings inside the app:

- Public base URL
- SFTP username
- SFTP password

The SFTP host is derived from the configured public URL. Published output includes:

- a course overview page
- a course page with lesson navigation
- individual lesson detail pages
- uploaded attachment files

The public website also includes:

- markdown rendering
- syntax highlighting for code blocks
- copy buttons on code blocks
- automatic highlighting of the current element
- automatic reload when a new publish version is detected

Lessons without lesson elements are not published and do not appear on the public website.

## Local development

Start the local Nextcloud stack:

```bash
docker compose up -d
```

Open Nextcloud at `http://localhost:8080` and log in with:

- User: `admin`
- Password: `admin`

If needed, enable the app with:

```bash
docker compose exec app php occ app:enable schoolplanner
```

## Frontend build

Install dependencies and build:

```bash
npm install
npm run build
```

For continuous frontend development:

```bash
npm run watch
```

## Release automation

The repository now contains a GitHub Actions workflow at [.github/workflows/release.yml](.github/workflows/release.yml) for automated Nextcloud releases.

The workflow only runs when a GitHub release is published. It does not run on normal pushes.

The workflow does the following when a GitHub release is published:

- installs PHP and Node.js dependencies
- builds the frontend bundle
- prepares a Nextcloud app package
- signs the app archive if signing secrets are configured
- uploads the generated `schoolplanner.tar.gz` to the GitHub release
- optionally pushes the release to the Nextcloud App Store

Required GitHub secrets:

- `APP_PRIVATE_KEY`: the Nextcloud app private key PEM
- `APP_PUBLIC_CERT`: the public certificate PEM
- `APPSTORE_TOKEN`: App Store API token for automated publishing

Notes:

- The app version in [appinfo/info.xml](appinfo/info.xml) still needs to match the release you publish.
- If `APPSTORE_TOKEN` is missing, the workflow still builds and uploads the signed release artifact to GitHub.
- Packaging is handled by [Makefile](Makefile) and the helper script [bin/tools/file_from_env.php](bin/tools/file_from_env.php).

## Tech stack

- Nextcloud App Framework
- Vue with `@nextcloud/vue`
- MariaDB
- Docker-based local development
- SFTP publishing with `phpseclib`
- Markdown rendering with `league/commonmark`
- ZIP import/export with PHP `ZipArchive`

## License

This project is licensed under the GNU Affero General Public License v3.0 or later.

See [LICENSE](LICENSE).
