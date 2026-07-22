/*
 * bogdany.com — cross-frame chat shortcut.
 *
 * Keyboard events don't cross iframe boundaries: index.htm's own
 * "Ctrl+/ toggles chat" listener only ever sees keystrokes typed in the
 * terminal document itself. The moment focus moves into a game's iframe
 * (i.e. as soon as you click into the game to actually play it), that
 * shortcut goes silent from the parent's point of view.
 *
 * Every game page includes this file so it works from inside the game
 * too: it listens for the same combo here, in the game's own document,
 * and asks the parent (via postMessage) to toggle chat — the same
 * mechanism gui.html already uses to run terminal commands.
 *
 * Safe to include even when a page is opened standalone (not embedded in
 * index.htm) — it simply has no parent to talk to and does nothing.
 */
(function () {
  "use strict";
  if (window.parent === window) return; // not embedded — nothing to toggle

  document.addEventListener("keydown", function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === "/") {
      e.preventDefault();
      window.parent.postMessage({ type: "bogdany-toggle-chat" }, "*");
    }
  });
})();
