'use strict'

const http = require('http')
const fs = require('fs')
const path = require('path')

const port = Number(process.env.PORT || 8090)
const dataDir = path.join(__dirname, 'data')

fs.mkdirSync(dataDir, { recursive: true })

const sendJson = (res, status, payload) => {
	res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' })
	res.end(JSON.stringify(payload, null, 2))
}

const collectBody = (req) => new Promise((resolve, reject) => {
	let body = ''
	req.on('data', (chunk) => {
		body += chunk.toString('utf8')
	})
	req.on('end', () => resolve(body))
	req.on('error', reject)
})

http.createServer(async (req, res) => {
	if (req.method === 'GET' && req.url === '/health') {
		return sendJson(res, 200, { ok: true })
	}

	if (req.method === 'PUT' && req.url.startsWith('/api/publish/')) {
		const key = req.url.replace('/api/publish/', '').replace(/[^a-zA-Z0-9-_]/g, '')
		if (!key) {
			return sendJson(res, 400, { error: 'missing-key' })
		}

		try {
			const rawBody = await collectBody(req)
			const payload = JSON.parse(rawBody || '{}')
			fs.writeFileSync(path.join(dataDir, `${key}.json`), JSON.stringify(payload, null, 2))
			return sendJson(res, 200, {
				ok: true,
				publicUrl: `http://localhost:${port}/public/${key}`,
			})
		} catch (error) {
			return sendJson(res, 400, { error: 'invalid-json', message: error.message })
		}
	}

	if (req.method === 'GET' && req.url.startsWith('/public/')) {
		const key = req.url.replace('/public/', '').replace(/[^a-zA-Z0-9-_]/g, '')
		const file = path.join(dataDir, `${key}.json`)
		if (!fs.existsSync(file)) {
			res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
			res.end('Not found')
			return
		}

		const payload = JSON.parse(fs.readFileSync(file, 'utf8'))
		const lessons = Array.isArray(payload.lessons) ? payload.lessons : []
		const lessonItems = lessons.map((lesson) => {
			const items = Array.isArray(lesson.publishedItems) ? lesson.publishedItems : []
			const itemMarkup = items.map((item) => `
				<li>
					<h3>${item.title || ''}</h3>
					<div>${item.description || ''}</div>
				</li>
			`).join('')
			return `
				<section>
					<h2>${lesson.title || ''}</h2>
					<p>${lesson.lessonDate || ''}</p>
					<div>${lesson.description || ''}</div>
					<ul>${itemMarkup}</ul>
				</section>
			`
		}).join('')

		res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
		res.end(`<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${payload.courseName || 'Schoolplanner'}</title>
  <style>
    body { font-family: sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; }
    section { border: 1px solid #ddd; border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
  </style>
</head>
<body>
  <h1>${payload.courseName || 'Kurs'}</h1>
  ${lessonItems || '<p>Keine veroeffentlichten Inhalte.</p>'}
</body>
</html>`)
		return
	}

	sendJson(res, 404, { error: 'not-found' })
}).listen(port, () => {
	console.log(`publish-mock listening on ${port}`)
})

