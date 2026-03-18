# School Planner

School Planner is a Nextcloud app for teachers who want to plan courses, structure lessons and publish selected lesson content for students as a public website.

The app uses Nextcloud Vue components, stores all planning data inside Nextcloud and can publish static course pages to a web server via SFTP.

## Features

- Create and manage courses in the Nextcloud app navigation
- Add lessons with date, topic, goal and markdown-based description
- Build a lesson flow from multiple elements
- Mark lesson elements as published
- Mark one published element as `Aktuell`
- Upload files per lesson element for student downloads
- Publish a course as a public website via SFTP
- Render markdown on the public website, including code blocks with syntax highlighting

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
2. Add lessons with date, topic, goal and description.
3. Add lesson elements with markdown and optional attachments.
4. Mark elements as `Veröffentlichen` when they should appear on the public website.
5. Mark one element as `Aktuell` to highlight where the class currently is.
6. Publish the course to the configured web server via SFTP.

Students can then open the published course page, browse lessons and access released files and materials.

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

## Tech stack

- Nextcloud App Framework
- Vue with `@nextcloud/vue`
- MariaDB
- Docker-based local development
- SFTP publishing with `phpseclib`
- Markdown rendering with `league/commonmark`

## License

This project is licensed under the GNU Affero General Public License v3.0 or later.

See [LICENSE](LICENSE).
