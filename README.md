# Suzen — Online 2-Player Setup (GoDaddy Linux hosting)

This turns Suzen into a client-server game: two players on two different
devices/browsers create or join a game with a short room code, and the
PHP + MySQL backend is the authority on whose turn it is and what moves
are legal — the browser just displays what the server says.

## How it fits together

```
suzen.html            the game page (upload to public_html, or a subfolder)
api/
  config.php           your MySQL credentials — EDIT THIS
  common.php            shared DB connection + JSON helpers (no editing needed)
  rules.php              PHP port of the movement rules (no editing needed)
  create.php               POST — start a new game, returns a room code
  join.php                  POST — join a game by room code
  state.php                  GET — poll the current board/turn
  move.php                    POST — submit a move (server validates it)
schema.sql            run this once to create the two database tables
```

`suzen.html` talks to the `api/` folder using **relative** URLs
(`api/create.php`, etc.), so as long as `api/` sits in the same folder
as `suzen.html` on your server, nothing else needs configuring.

## 1. Create the database (cPanel)

1. In cPanel, open **MySQL® Databases**.
2. Create a new database (e.g. `suzen`) — cPanel will name it something
   like `yourcpaneluser_suzen`.
3. Create a database user with a password, and add that user to the
   database with **All Privileges**.
4. Open **phpMyAdmin**, select your new database, go to the **Import**
   tab, and upload `schema.sql` from this package (or paste its
   contents into the **SQL** tab and run it). This creates two tables:
   `games` and `game_state_history`.

## 2. Set your credentials

Open `api/config.php` and fill in the four values from step 1:

```php
define('DB_HOST', 'localhost');                 // almost always localhost on GoDaddy
define('DB_NAME', 'yourcpaneluser_suzen');
define('DB_USER', 'yourcpaneluser_suzen');
define('DB_PASS', 'the-password-you-set');
```

## 3. Upload the files

Using cPanel's **File Manager** or an FTP client, upload:

- `suzen.html`
- the whole `api/` folder (including `.htaccess`, `config.php`, and
  all the `.php` files)

into `public_html/` (site root) or a subfolder like
`public_html/suzen/` if you want it at `yoursite.com/suzen/`. Keep
`suzen.html` and `api/` as siblings — same folder — either way.

No build step, no Node, no Composer — it's plain PHP, which GoDaddy's
Linux hosting runs out of the box. You just need PHP 7.4+ (8.x is
fine) with the PDO MySQL extension, which is on by default.

## 4. Play

1. Visit `suzen.html` on your domain.
2. In the **Opponent** panel, choose **Online**.
3. One player clicks **Create New Game** and shares the 6-character
   room code shown.
4. The other player types that code into **Have a code?** and clicks
   **Join**.
5. The board updates automatically every ~2 seconds for both players
   (simple polling — no WebSocket server needed on shared hosting).

If a browser is closed and reopened, switching to **Online** shows a
**Reconnect to last game** option (it remembers your seat via
`localStorage` in that browser).

## About the "slowly changing dimension" database design

`game_state_history` never overwrites a board — every move **inserts
a new row** instead of updating the old one, and the row it replaces
gets its `valid_to` timestamp stamped and `is_current` flipped to 0.
That's the standard **SCD Type 2** pattern applied to "the state of
this game" as a dimension that changes over time:

| column         | meaning                                             |
|----------------|------------------------------------------------------|
| `valid_from`    | when this version of the board became current        |
| `valid_to`      | when it stopped being current (`NULL` = still current)|
| `is_current`    | 1-row-per-game fast lookup for "what's live right now"|
| `move_number`   | 0, 1, 2… — a simple sequence alongside the dates      |
| `last_move_json`| what changed to produce this version, for replay/audit|

Two things fall out of this for free:

- **Full replay / audit trail.** Nothing is ever deleted, so you can
  reconstruct any game move-by-move:
  ```sql
  SELECT move_number, current_turn, valid_from, last_move_json
  FROM game_state_history
  WHERE game_id = ?
  ORDER BY valid_from;
  ```
- **"As of" queries.** You can ask what the board looked like at any
  point in time:
  ```sql
  SELECT board_json FROM game_state_history
  WHERE game_id = ? AND valid_from <= '2026-07-21 10:15:00'
    AND (valid_to IS NULL OR valid_to > '2026-07-21 10:15:00');
  ```

The "current" row (`is_current = 1`) is what `state.php` and
`move.php` read and write against, so normal gameplay is just as fast
as a single mutable row would be — you only pay for the history when
you actually query it.

## Notes / things you may want to change later

- **Security is minimal by design.** A room code + a random 32-char
  token is enough to keep casual players separated, but tokens are
  passed in plain query strings/JSON bodies — fine over HTTPS (which
  GoDaddy gives you a free cert for), not meant to resist a
  determined attacker. Don't reuse these tokens as real account auth.
- **Polling, not push.** Every connected browser asks `state.php`
  every 2 seconds. That's trivial load for two players, but if you
  ever wanted many simultaneous games, you'd eventually want to move
  to WebSockets or Server-Sent Events — not available on typical
  GoDaddy shared hosting without a VPS-tier plan.
- **Old games pile up.** Nothing currently deletes finished games or
  their history. If that matters to you, a simple cron-triggered
  script (cPanel has a Cron Jobs UI) deleting `games` rows older than
  N days — the `ON DELETE CASCADE` on `game_state_history` will clean
  up their history automatically — is the easiest way to prune.

---

# Site chat (separate feature, separate database)

A small sitewide chat lives in the terminal (`index.htm`) itself — click
**[chat]** in the toolbar, or press **Ctrl+/** (**Cmd+/** on Mac) to toggle
it. It's a side panel, not the game window, so opening it never interrupts
whatever game is currently running.

```
chat-api/
  config.php       your MySQL credentials for the "chats" database — EDIT THIS
  common.php       shared DB connection + JSON helpers (no editing needed)
  send.php         POST — post a message
  history.php      GET  — fetch recent messages / poll for new ones
  schema.sql        run this once to create the messages table
```

## Setup

Same pattern as Suzen, but its own database — **don't** point this at the
`suzen` database:

1. In cPanel → **MySQL® Databases**, create a new database (e.g. `chats`)
   and a user with **All Privileges** on it.
2. In phpMyAdmin, run `chat-api/schema.sql` against that database.
3. Edit `chat-api/config.php` with that database's host/name/user/password.
4. Upload `chat-api/` (including `.htaccess`) as a sibling of `index.htm`.

## Security notes

- Every query in `chat-api/` uses a PDO **prepared statement** with bound
  parameters — request data is never concatenated into SQL, which is what
  actually prevents SQL injection (not escaping/filtering).
- Message bodies are rendered client-side with `textContent`, never
  `innerHTML`, so a message can't be interpreted as HTML/script no matter
  what it contains.
- `send.php` requires an `application/json` request body, which a plain
  cross-site `<form>` can't produce — that blocks the classic CSRF vector
  even though this chat has no login system to protect.
- Basic flood control (per browser-generated client id, not IP): one
  message per 1.5s, max 20/minute.
- The chat only remembers your display name and identity **after you
  accept cookies** in the site's existing GDPR banner; `delete-cookies`
  wipes it along with everything else.

