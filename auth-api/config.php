<?php
// Accounts (users/roles/sessions) live in the SAME database as chat
// messages — see chat-api/schema.sql. Rather than keep a second copy of
// the same four credentials here (and risk them drifting apart), this
// file just points at chat-api/config.php. Edit the values there; both
// APIs will pick them up.
require_once __DIR__ . '/../chat-api/config.php';
