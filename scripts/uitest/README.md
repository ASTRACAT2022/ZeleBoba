# UI test helpers

Playwright scripts used to screenshot and audit the ZeleBoba web UI. The host has
no browser libs, so they run inside the official Playwright image against the
local app (`http://127.0.0.1:8099`).

```sh
# Public pages (login/register) + mobile + dark
docker run --rm --network host \
  -v "$PWD/scripts/uitest:/s:ro" -v "$PWD/.shots/out:/out" \
  -e TARGET="http://127.0.0.1:8099" \
  mcr.microsoft.com/playwright:v1.44.0-jammy \
  bash -lc "cd /tmp && npm i playwright@1.44.0 >/dev/null 2>&1 && cp /s/shot-docker.js . && node shot-docker.js"

# Authenticated pages (admin + client). Needs a session cookie file at /tmp/sid.txt.
# Mint an audit session (id column stores sha256(raw)):
#   RAW=$(openssl rand -hex 32); HASH=$(printf '%s' "$RAW" | sha256sum | cut -d' ' -f1)
#   docker exec zeleboba-db-1 psql -U billing -d billing -c \
#     "INSERT INTO sessions(id,user_id,csrf,expires_at,admin_verified_until)
#      VALUES ('$HASH','<admin_user_id>','$(openssl rand -hex 32)',$(( $(date +%s)+1800 )),$(( $(date +%s)+1800 )));"
#   printf '%s' "$RAW" > /tmp/sid.txt
docker run --rm --network host \
  -v "$PWD/scripts/uitest/shot-auth.js:/s.js:ro" -v "/tmp/sid.txt:/tmp/sid.txt:ro" \
  -v "$PWD/.shots/auth:/out" -e TARGET="http://127.0.0.1:8099" \
  mcr.microsoft.com/playwright:v1.44.0-jammy \
  bash -lc "cd /tmp && npm i playwright@1.44.0 >/dev/null 2>&1 && cp /s.js . && node s.js"
```

- `shot-docker.js` — public pages + dark + mobile screenshots.
- `shot-auth.js` — client/admin pages with a session cookie; reports horizontal overflow.
- `align-audit.js` — checks card-grid row widths, radii, font sizes, left edges.
- `cascade-docker.js` — dumps resolved design tokens and button styles.
- `probe-chain.js` — walks the DOM chain to find what causes overflow.

Always delete the audit session afterwards.
