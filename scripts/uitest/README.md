# UI verification scripts

Playwright-based checks for the client cabinet UI. There are **no browser
libraries on the host**, so every script runs inside the official Playwright
image, mounting the script and an admin session cookie file read-only:

```bash
RAW=$(openssl rand -hex 32); HASH=$(printf '%s' "$RAW" | sha256sum | cut -d' ' -f1)
docker exec zeleboba-db-1 psql -U billing -d billing -c \
  "INSERT INTO sessions(id,user_id,csrf,expires_at,admin_verified_until)
   VALUES ('$HASH', (SELECT id FROM users WHERE role='admin' LIMIT 1),
           '$(openssl rand -hex 32)', <exp>, <exp>);"   # exp = unix now + 3600
printf '%s' "$RAW" > /tmp/sid.txt   # cookie holds the RAW value, table holds sha256(raw)

docker run --rm --network host \
  -v "$PWD/scripts/uitest/<script>.js:/tmp/s.js:ro" \
  -v "/tmp/sid.txt:/tmp/sid.txt:ro" \
  -e TARGET="http://127.0.0.1:8099" \
  mcr.microsoft.com/playwright:v1.44.0-jammy \
  bash -lc "cd /tmp && npm i playwright@1.44.0 >/dev/null 2>&1 && node s.js"
```

Delete the session row when done (`DELETE FROM sessions WHERE id='<hash>'`).

## Scripts

| Script | What it checks |
|---|---|
| `shot-client.js` | Renders each page at desktop + mobile, saves PNGs, reports horizontal overflow. `PAGES`, `TARGET` env. |
| `final-verify.js` | One pass: overflow + WCAG contrast + tap-target size, light + dark, mobile + desktop. |
| `contrast.js` | Full WCAG AA contrast audit on desktop pages, light + dark. |
| `mob-layout-check.js` | WCAG contrast on mobile pages, light + dark. |
| `mob-overflow.js` | Horizontal overflow at a given viewport width (`W` env). |
| `mob-problems.js` | Page height + tap targets under 36px per mobile page. |
| `align2.js` | Radii, font sizes, left edges, grid alignment. |
| `cascade-docker.js` / `probe-chain.js` / `probe-link`-style | CSS cascade / computed-style probes for debugging a specific token. |
| `shot-auth.js` / `shot-docker.js` | Screenshots of anonymous/auth and containerised pages. |

## Gotchas

- Computed colours may come back as `color(srgb 0.58 …)` (channels 0–1), not
  `rgb()`. Parsers must handle both, otherwise light colours look near-black.
- A naive "walk up ancestors for the first opaque background" sampler gives
  false contrast failures for transparent nav links and hidden panels;
  compositing every ancestor with alpha is required.
- Admin sessions: `sessions.id` is **sha256(raw cookie value)**, never the raw.
