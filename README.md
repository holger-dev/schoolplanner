# Schoolplanner

Nextcloud app for planning courses, lessons and lesson flow content for teachers.

## Scope

- Courses in the left navigation
- Lessons inside each course with date, title and markdown description
- Lesson flow items with markdown content and publish flag
- Publishing to an external web endpoint
- Local Docker setup with Nextcloud and a mock publish server

## Local development

1. Start the stack:

```bash
docker compose up -d
```

2. Open Nextcloud at `http://localhost:8080`
3. Log in with `admin` / `admin`
4. Enable the `School Planner` app in Apps if it is not auto-detected
5. Use `http://publish-mock:8090/api/publish` as publish endpoint inside the app settings when testing from inside the Nextcloud container

The mock publish server exposes public pages at `http://localhost:8090/public/<slug>`.

## Frontend build

Install dependencies and build the app bundle:

```bash
npm install
npm run build
```

For active development:

```bash
npm run watch
```

## Current implementation status

This repository now contains:

- Nextcloud app skeleton with routes, controllers and migration
- Persistent storage for courses, lessons and lesson items
- Vue-based UI using Nextcloud components for the main interactions
- Basic publishing workflow to an external HTTP endpoint
- `TODO.md` for the remaining product and engineering work
