# Fetching the captured image from the Hikvision terminal

## The short version

It isn't impossible. But it *is* impossible the way the code was doing it, and no
amount of retry logic, auth juggling, or URL rewriting was going to change that.

The old flow was:

1. Search events → get `pictureURL` (`/LOCALS/pic/...@WEB...`)
2. Later, when a page renders, `GET` that URL
3. Hope the picture is still there

`/LOCALS/pic/` is the terminal's **live capture buffer**, not an archive. It holds
a handful of recent frames and recycles them within seconds on a busy door. Worse,
when the frame you asked for is gone the device doesn't 404 — it answers `200` with
a generic placeholder that is a structurally perfect JPEG. That's why the debug
output looked so maddening: valid markers, sane byte count, real `Content-Type`,
and a solid green square.

Your existing notes had already ruled out every pull-side variable — digest vs
basic, User-Agent filtering, the `@WEB` suffix, `security=1`, the `/ISAPI` prefix.
That work wasn't wasted; it's what proves the problem isn't in the request. The
request is fine. The picture is simply gone by the time it arrives.

**So stop pulling. Make the device push.**

Hikvision's ISAPI guide for face recognition terminals documents a *listening
mode*: register an HTTP endpoint on the device, and when an event fires the device
opens a connection to you and `POST`s the event body **together with the raw JPEG
bytes** as `multipart/form-data`. The photo arrives at the instant of capture.
There is no buffer to lose and no race to lose it to.

This also sidesteps the objection that killed `poll_events.php`: pushing needs **no
persistent background process**. It's a plain PHP endpoint the device calls.

## What changed

| File | Status | What it does |
|---|---|---|
| `hik_event_listener.php` | **new** | Receives pushed events, extracts the JPEG, validates and stores it |
| `hik_photo_store.php` | **new** | Shared on-disk photo store — one keying scheme for reader and writer |
| `hik_setup_listener.php` | **new** | Registers the callback on the device; diagnoses the whole chain |
| `hik_config.php` | modified | Listener IP/path/token, photo dir, retention |
| `HikvisionAPI.php` | modified | `httpHosts` config methods, `ContentMgmt/download`, raw transport |
| `face_image.php` | modified | Store first, then `ContentMgmt/download`, then the old direct GET |
| `index.php` | modified | Renders a thumbnail when a pushed photo exists even with no `pictureURL` |
| `module_htaccess.txt` | **new** | → save as `face_recognition/.htaccess` |
| `cache_htaccess.txt` | **new** | → save as `face_recognition/cache/.htaccess` |

Nothing was removed. The direct-GET path still runs as a last resort, so events
from before the listener was switched on behave exactly as they do today.

## Deployment

**1. Copy the `.htaccess` files first.** `cache/` will now hold employee face
photos. Without `cache/.htaccess` they are fetchable by URL with no login —
`face_image.php` is behind RBAC, but the raw files would not be.

**2. Fill in `hik_config.php`:**

```php
define('TWM_LISTENER_IP',   '192.168.100.x');  // this server's LAN IP
define('TWM_LISTENER_PORT', 80);
define('TWM_LISTENER_PATH', '/TWM/face_recognition/hik_event_listener.php');
define('HIK_LISTENER_TOKEN', '…');             // php -r "echo bin2hex(random_bytes(24));"
```

`TWM_LISTENER_IP` must be reachable **from the terminal's subnet**. Not
`127.0.0.1` — the device opens its own TCP connection, so loopback can never
work. This is the single most common thing to get wrong.

**3. Make `cache/photos/` writable** by the Apache/PHP user.

**4. Open `hik_setup_listener.php`** in the browser. It prints the device's
current listening-host config, checks your settings, then applies on a button
press. Hit **Test reachability** — that asks the device to probe your URL, which
catches a wrong IP or a firewall immediately.

**5. Walk up to the terminal and authenticate.** Reload the setup page. A `SAVED`
line in the listener log means the chain works end to end.

## Two things to know

**The token sits in the device's config.** The device can't hold a TWM session, so
`hik_event_listener.php` has to live outside `auth_check()`. It's guarded by the
shared token plus a source-IP check against `HIKVISION_IP`. The trade-off: that
token is stored on the terminal and visible to anyone with its admin password.
Since that's the same password that already grants full access to the face
database, this doesn't widen your exposure — but it's worth knowing the token is
not a secret from device admins. The `Require ip` block in `module_htaccess.txt`
adds a network-level layer if you want belt and braces.

**Some firmware drops the query string** from the callback URL. If that happens the
listener accepts the push on the source-IP check alone and logs a `WARN`. That's
deliberate — better a logged weaker check than silently losing every photo — but
if you see it, you're relying on the IP check, so consider the `Require ip` block.

## If push turns out to be unsupported

`hik_setup_listener.php` reports whether the firmware advertises
`HttpHostNotificationCap`. If it doesn't, the pull-side equivalent is
`GET /ISAPI/Event/notification/alertStream` — a long-lived `multipart/mixed`
connection carrying the same event-plus-JPEG payloads. Same data, same
immunity to buffer rotation. The catch is that it needs a persistent process
holding the connection open, which is exactly what you didn't want. Worth
trying only if push is genuinely unavailable.

## The `ContentMgmt/download` middle path

`face_image.php` now also tries `POST /ISAPI/ContentMgmt/download` with the
`pictureURL` as a `playbackURI`, before falling back to the direct GET. This goes
through the device's storage manager rather than the web UI's static file handler,
so on some firmware it returns a real frame the direct GET has already lost.

Treat this as a bonus, not the fix. It may do nothing on V3.3.4 — the debug view
will tell you within one page load. It costs one request and it's the only thing
that could recover photos for events that happened *before* the listener existed.

## Round two, and the regression it caused

The DevTools capture turned up three real defects. Fixing them, I also broke
the one thing that was working. Recording both here so the history is honest.

### The regression (now reverted)

I shipped two changes with bad defaults:

- An **age gate** at 600s. Your dashboard shows a full day of events, so almost
  every row is older than ten minutes — which meant I suppressed the request
  entirely for nearly all of them, including whichever row *was* returning a
  real photo. I traded working functionality for quieter console output. Wrong
  call: the noise was cosmetic, the photo wasn't.
- **`ContentMgmt/download` running before the direct GET**, two speculative
  POSTs per thumbnail. That is triple the original request volume against a
  device that locks itself out under repeated failures — your own `lockStatus`
  handling is proof it does that. So the proven path was being starved of a
  responsive device by an unproven one running in front of it.

Both are reverted. Every new setting now defaults to the original behaviour:

| Setting | Default | Meaning |
|---|---|---|
| `HIK_LIVE_FETCH_MAX_AGE` | `0` | Never skip on age — always try, as before |
| `HIK_MISS_TTL` | `0` | Negative cache off |
| `HIK_TRY_CONTENTMGMT` | `false` | Extra endpoint off |
| `HIK_DEVICE_TIMEOUT` | `15` | Same as the original hardcoded value |

The direct GET now runs first and nothing speculative is permitted in front of
it. Request count per page load is identical to your original code — I checked
this by replaying your five events through the logic rather than assuming.

The throttles remain available if the terminal ever does start struggling, but
they are opt-in, which is what they should have been from the start.

### Also reverted: 404 vs 204

I changed the "no photo" response to 204 so DevTools would stop showing red
rows, on the theory that `<img onerror>` fires for an empty body anyway. That
is probably true, but it is not worth betting the entire photo column on a
browser behaviour I hadn't tested. Back to **404**, which your existing
`onerror` handler was built against and is known to swap in the placeholder
badge. The reason still travels in an `X-Hik-Reason` header.

So expect red rows in DevTools again for events whose frame the device no
longer holds. That noise is the honest signal, and it shrinks on its own as the
store fills from pushed events.

### The one fix from that round worth keeping

Your log showed `pictureURL` arrives as an absolute URL with **no port**:
`http://192.168.100.59/LOCALS/...`. `fetchImage()` checked the host matched and
then used the URL verbatim, so it always hit port 80 regardless of
`HIKVISION_PORT`. Harmless today because your port *is* 80 — it would have bitten
you silently the day the device moved. It now rebuilds against the configured
base, with the host check still running first so the SSRF guard is unchanged.

### Before you judge this build

**The terminal may still be locked out** from the request storm my previous
revision caused. A `lockStatus` lockout lasts minutes, and while it holds,
*nothing* will fetch no matter how correct the code is. Give it five or ten
minutes, or reboot the terminal, before concluding this build doesn't work.

Then check one specific thing rather than eyeballing the grid: take the single
most recent face event and open

```
face_image.php?debug=1&serial=<serial>&url=<its pictureURL>
```

That prints the HTTP code, byte count and first/last bytes for every source. If
the direct GET shows `ffd8 … ffd9` and a sane byte count, the fetch path is
healthy and older rows are simply gone from the device. If it shows a device
error or a lock message, that's the lockout, not the code.

## Debugging

`face_image.php?debug=1&serial=…&url=…` walks all three sources and reports HTTP
code, byte count, and first/last bytes for each, so you can see exactly which one
produced what. It also prints the event's age and the `hik_store_decide()` verdict, and it
always tries `ContentMgmt/download` regardless of `HIK_TRY_CONTENTMGMT` — that is
how you find out whether the endpoint does anything on V3.3.4 before paying for it
on every thumbnail. Debug mode ignores the age gate and negative cache too: when
you're debugging you want the real answer, not a cached verdict. It still shows the `@WEB`-stripped variant as a diagnostic — a
perfectly valid JPEG that is the wrong image — to keep the trap visible.

That stripped variant is deliberately excluded from the live path. It's the one
mistake here that's genuinely hard to recover from: because it *is* a valid image,
it passes every check and gets cached permanently as the wrong photo.

## One thing I couldn't verify

I don't have your device, so the exact multipart field names and whether V3.3.4
puts the picture part in `$_FILES` or `$_POST` are unknown. The listener therefore
checks both, plus its own raw multipart parser, and identifies any JPEG by magic
bytes rather than by field name. If all three come up empty it logs `NOPARSE` with
the content type and body length — send me that line and I can narrow it down.
