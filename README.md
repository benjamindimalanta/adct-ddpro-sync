# ADCT DD Pro Sync

Imports vehicle listings from the DD Pro (Deal Drive) JSON feed into WooCommerce
products on autodealsuae.com.

**Version 1.0.0** — tested against the live feed on the local staging site
(`http://localhost:8888/autodealsuae`, WordPress 6.9 / PHP 8.2 / MariaDB 10.4).

---

## The one design rule

The feed owns **structured data**. Humans own **anything that earns rankings**.

| Feed owns (synced automatically) | Humans own (never overwritten) |
|---|---|
| Specs: make, year, trim, body type, cylinders, colour, transmission, fuel, power | `post_title` |
| Price | `post_content` |
| Mileage | `post_excerpt` |
| Warranty flag | `post_name` (slug) |
| Stock / sold status | All Yoast fields (title, meta description, focus keyword) |
| Photos | Sales agents, videos, insurance, showroom landline |

This split exists because the catalogue averages **1,421 characters** of body copy
and a Yoast content score of **90/100** across 450 cars, while the feed supplies a
**26-character** placeholder description (`<p>DD ID: 161392-CHCZT</p>`). Letting
the feed own content would be roughly a 98% content reduction per page.

Enforced in exactly two constants in `includes/class-importer.php`:

- `SYNC_ON_UPDATE` — the only ACF fields an update may write (`mileage`, `warranty`)
- `NEVER_UPDATE` — post fields excluded from every `wp_update_post()` payload

Specs are deliberately **not** re-synced on update: a 2021 Patrol does not become
a 2022 Patrol, so rewriting them each run only risks clobbering a manual fix.

---

## Setup

**1. Store the feed URL in `wp-config.php`**, not in the database. The URL carries
an unauthenticated token — anyone holding it can read your full stock list
including VINs, and a database export of this site is committed to a backup repo.

```php
define( 'ADCT_DDPRO_FEED_URL', 'https://services.deal-drive.com/feeds/ddexport/1104-....json' );
```

**2. Activate the plugin.** Creates the `wp_adct_ddpro_log` table and schedules
two cron events.

**3. Go to WooCommerce → DD Pro Sync** and press **Dry run**. Nothing is written;
the log shows exactly what would change.

**4. Leave "Enable scheduled sync" OFF** until the dry run looks right.

### Recommended settings

| Setting | Value | Why |
|---|---|---|
| Status for new cars | **Draft** | The generated title is a starting point, not publishable copy |
| When a car leaves the feed | **Mark out of stock, keep page live** | Drafting a ranking page turns it into a 404 |
| Cars per sync run | 25 | Keeps a run inside PHP limits |
| Photos per batch | 20 | ~190 KB each; 25 per car |
| Condition (used) | `Pre-Owned` | Matches 188 existing cars |

> The live catalogue is inconsistent here — `Pre-Owned` on 188 cars and
> `Pre - Owned` on 152. Pick one and standardise; the plugin writes whichever you
> configure.

---

## How it works

**Matching.** Each imported product stores `_ddpro_id`. That is the only link
between a feed listing and a product — there is no shared key with the 450
pre-existing cars, so a car already on the site will be created a second time if
it also appears in the feed. Reconcile manually during changeover by setting
`_ddpro_id` on the existing product.

**Change detection.** The feed sends no `Last-Modified`, `ETag` or `Cache-Control`
header, so conditional GET is impossible. Every run fetches the whole document
and compares an MD5 of each listing against `_ddpro_hash`. Unchanged cars are
skipped without a database write.

**Photos.** Queued in `_ddpro_photo_queue` and drained by a separate 5-minute
cron in bounded batches, so a 25-photo car cannot time out the listing sync.
Already-imported URLs are tracked in `_ddpro_photo_map`, so nothing downloads
twice. Files are renamed from the CDN's opaque hash to a slug of the product
title and given alt text, because image filenames and alt text are part of the
site's SEO.

**Safety guard.** If the feed returns zero listings the run aborts rather than
marking every product sold.

**Unknown status codes.** Only `inSale` has been observed. Anything not in the
known sold/archived list is treated as still available, so an unrecognised code
can never silently unpublish stock.

---

## Cron

| Hook | Schedule | Job |
|---|---|---|
| `adct_ddpro_cron_sync` | hourly | Fetch feed, create/update products |
| `adct_ddpro_cron_photos` | every 5 min | Download queued photos |

WP-Cron only fires on site traffic. On a low-traffic period the feed goes stale,
so on production disable WP-Cron and use a real system cron:

```
define( 'DISABLE_WP_CRON', true );
```

```bash
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
```

---

## Verified behaviour

Tested on staging against the live 4-car feed:

- 4 products created as drafts; product count 450 → 454
- ACF values written with correct field keys (`make` → `field_68820fad66506`), so
  they render in the product editor
- VIN written to `_sku`; `product_brand` terms reused, not duplicated
- Every mapped value already existed in the catalogue — no new vocabulary
  variants (`SUV` 108 cars, `Automatic` 422, `Petrol` 402, `GCC Specs` 259,
  `Pre-Owned` 188)
- Photo sideload produced
  `2021-nissan-patrol-nismo-gcc-specs-no-accidents-5-6l-v8-1.jpg` with alt text
- Second run reported 4 unchanged, 0 writes — idempotent
- **SEO protection test passed**: a product's title, content, excerpt, focus
  keyword and meta description were hand-edited, the hash cleared to force a
  re-sync, and all five survived untouched

---

## WP-CLI commands

```bash
wp ddpro feed-check              # what the feed contains + channel-filter test
wp ddpro reconcile               # suggest links to existing products (writes nothing)
wp ddpro reconcile --min-score=60 --format=csv > review.csv
wp ddpro link --dd-id=<id> --post-id=<n>
wp ddpro unlink --post-id=<n>
wp ddpro sync --dry-run
wp ddpro sync
wp ddpro photos                  # drain the photo queue once
wp ddpro status                  # which products the feed manages
```

---

## Changeover: linking the existing 450 cars

**The problem.** There is no shared key. Existing products carry a 5–6 digit
stock number in `_sku` (e.g. `957556`); the feed supplies a 17-character VIN
(e.g. `SJAAB1ZV5HC015916`). So a car already published on the site will be
imported a **second time** unless it is linked first.

**The fix.** `wp ddpro reconcile` scores every feed listing against the
catalogue and prints ranked candidates. It writes nothing. You confirm each pair
by eye, then run `wp ddpro link`.

Scoring: year and brand must **both** agree or the candidate is discarded. Model
tokens in the title add up to 40, an exact price adds 20, close mileage adds 10.
Bands are high ≥85, medium ≥60, low ≥40, weak below.

Brand comparison canonicalises the catalogue's manual-entry errors, so `Porshe`
matches Porsche, `Bently` matches Bentley, and `Range Rover` matches Land Rover.

**Do not automate this step.** A real run against the 4-car feed produced
*four* candidates for the 2023 Defender 110, all scoring 70, at 319k / 275k /
339k / 299k against a feed price of 379k — indistinguishable by score, and
probably four different cars. Nothing scored above 84. A wrong link starts
syncing the wrong price onto a ranking page.

Linking deliberately clears `_ddpro_hash`, so the next sync brings that car's
price, mileage and photos up to date once while leaving its title, content and
Yoast fields alone. Unlinking also **cancels any pending photo downloads**.

### Verified changeover test

Linked feed listing `162251-CHCZT` to existing published product **#3975**
(*2017 Bentley Bentayga First Edition W12*), then ran a real sync:

| | Result |
|---|---|
| Duplicate created | **No** — `created=0, updated=1` |
| `post_title` | unchanged |
| `post_content` | unchanged (md5 compared) |
| `post_excerpt` | unchanged |
| Yoast focus keyword | unchanged (`Bentley Bentayga First Edition W12`) |
| Yoast meta description | unchanged |
| Post status | unchanged (`publish`) |
| Price | 219,000 → **229,000** (feed-owned) |
| Mileage | 110,032 → **123,580** (feed-owned) |

All original values were then restored, including the product's own 17-image
gallery.

---

## Known gaps — confirm with DD Pro before going live

1. **The WordPress/Dubizzle tick is not in the feed payload.** There is no
   `channels` / `publish_to` field in any of the 49 fields, so the filtering must
   happen server-side per feed URL. The feed being JSON says nothing about which
   *listings* it contains — with only 4 cars in a test account, "4 returned" is
   equally consistent with "filtered to WordPress" and "unfiltered, everything".

   **Test it yourself, no vendor reply needed:** run `wp ddpro feed-check`, then
   tick one car in DD Pro for **Dubizzle only**, wait for the feed to refresh,
   and run it again. If that car appears, the feed is not channel-filtered and
   every Dubizzle listing will reach the website.
2. **What does a sold car look like?** Does `status.code` change, or does the car
   vanish from the feed? This decides whether "out of stock" ever fires.
3. **Descriptions are placeholders.** Fine while DD Pro is a test account; the
   plugin detects the `DD ID:` placeholder and substitutes a spec list instead.
4. **`equipments` is inconsistent** — present on 1 of 4 listings. Not currently
   mapped to any field; the site has no equipment field.
5. **`body_color` is missing on some listings** (the Nissan Patrol), so `color`
   imports empty rather than guessed.

---

## Removing the test data

The staging site currently has 4 draft products from this test:

```bash
wp post delete $(wp post list --post_type=product --post_status=draft --meta_key=_ddpro_id --format=ids) --force
```

## Files

```
adct-ddpro-sync.php          bootstrap, cron registration
includes/class-settings.php  settings + wp-config constant handling
includes/class-logger.php    log table
includes/class-feed-client.php  HTTP fetch, JSON validation, empty-feed guard
includes/class-mapper.php    DD Pro -> ACF/Woo mapping + vocabulary normalisation
includes/class-importer.php  create/update/sold logic, photo queue
includes/class-admin.php     WooCommerce -> DD Pro Sync screen
```
