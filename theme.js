/*
 * bogdany.com shared theme engine.
 *
 * Include this with `<script src="theme.js"></script>` as early as possible
 * in <head> (before your own <style> block) on any page that wants to match
 * the site's chosen accent color. It will:
 *
 *   1. Apply the saved color immediately on load, so the page never flashes
 *      the default amber before switching to the real theme.
 *   2. Derive --amber, --amber-dim, --amber-faint and --glow CSS custom
 *      properties on <html>, which your stylesheet can reference the same
 *      way index.htm does:
 *        :root { --amber: #ffb000; --amber-dim: #c07f1a; ... }
 *   3. Listen for { type: "bogdany-theme", hex } postMessages, so pages
 *      embedded in index.htm's game window stay in sync when the user
 *      changes the color while your page is open.
 *   4. Expose a small API for pages that let the user change the color
 *      themselves (currently only index.htm's `color` command / panel):
 *        window.applyBogdanyTheme(hex, persist)
 *        window.bogdanyCurrentThemeHex()
 *        window.BOGDANY_COLOR_PRESETS
 */
(function () {
  "use strict";

  var STORAGE_KEY = "bogdany-theme-hex";
  var CONSENT_KEY = "bogdany-cookie-consent";
  var DEFAULT_HEX = "ffb000";

  // ---- cookies (theme.js is self-contained, so it keeps its own tiny copy) ----
  function getCookie(name) {
    var esc = name.replace(/[.$?*|{}()\[\]\\\/+^]/g, "\\$&");
    var match = document.cookie.match(new RegExp("(?:^|; )" + esc + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : null;
  }
  function setCookie(name, value, days) {
    var expires = "";
    if (days) {
      var d = new Date();
      d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
      expires = "; expires=" + d.toUTCString();
    }
    document.cookie = name + "=" + encodeURIComponent(value) + expires + "; path=/; SameSite=Lax";
  }
  function hasConsent() { return getCookie(CONSENT_KEY) === "accepted"; }

  var COLOR_PRESETS = [
    ["amber", "ffb000"],
    ["green", "33ff66"],
    ["cyan", "33e0ff"],
    ["violet", "b266ff"],
    ["rose", "ff4d8d"],
    ["white", "e8e8e8"]
  ];

  // ---- hex/rgb/hsl helpers ----

  function hexToRgb(hex) {
    hex = (hex || "").replace("#", "").trim().toLowerCase();
    if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    if (!/^[0-9a-f]{6}$/.test(hex)) return null;
    return {
      r: parseInt(hex.slice(0, 2), 16),
      g: parseInt(hex.slice(2, 4), 16),
      b: parseInt(hex.slice(4, 6), 16)
    };
  }

  function rgbToHex(rgb) {
    function c(v) { return Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, "0"); }
    return "#" + c(rgb.r) + c(rgb.g) + c(rgb.b);
  }

  function rgbToHsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b);
    var h = 0, s = 0, l = (max + min) / 2;
    var d = max - min;
    if (d !== 0) {
      s = d / (1 - Math.abs(2 * l - 1));
      switch (max) {
        case r: h = ((g - b) / d) % 6; break;
        case g: h = (b - r) / d + 2; break;
        default: h = (r - g) / d + 4;
      }
      h *= 60;
      if (h < 0) h += 360;
    }
    return { h: h, s: s, l: l };
  }

  function hslToRgb(h, s, l) {
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var x = c * (1 - Math.abs((h / 60) % 2 - 1));
    var m = l - c / 2;
    var r = 0, g = 0, b = 0;
    if (h < 60)       { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else              { r = c; g = 0; b = x; }
    return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
  }

  // ---- core ----

  function deriveVars(hex) {
    var rgb = hexToRgb(hex);
    if (!rgb) return null;
    var hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
    var dim = hslToRgb(hsl.h, Math.min(1, hsl.s * 0.9 + 0.1), Math.max(0.14, hsl.l * 0.62));
    var faint = hslToRgb(hsl.h, Math.min(1, hsl.s * 0.8 + 0.1), Math.max(0.09, hsl.l * 0.36));
    return {
      amber: "#" + hex,
      amberDim: rgbToHex(dim),
      amberFaint: rgbToHex(faint),
      glow: "rgba(" + rgb.r + "," + rgb.g + "," + rgb.b + ",0.28)"
    };
  }

  function applyTheme(hex, persist) {
    hex = (hex || "").replace("#", "").trim().toLowerCase();
    var vars = deriveVars(hex);
    if (!vars) return false;
    var root = document.documentElement.style;
    root.setProperty("--amber", vars.amber);
    root.setProperty("--amber-dim", vars.amberDim);
    root.setProperty("--amber-faint", vars.amberFaint);
    root.setProperty("--glow", vars.glow);
    if (persist && hasConsent()) {
      setCookie(STORAGE_KEY, hex, 365);
    }
    return true;
  }

  function savedHex() {
    var v = getCookie(STORAGE_KEY);
    if (v) return v.replace("#", "").trim().toLowerCase();
    return DEFAULT_HEX;
  }

  // apply immediately — this is what actually fixes "wrong color on load"
  applyTheme(savedHex(), false);

  // stay in sync if this page is embedded (e.g. inside index.htm's game window)
  window.addEventListener("message", function (e) {
    var data = e.data;
    if (!data || data.type !== "bogdany-theme") return;
    applyTheme(data.hex, false);
  });

  window.applyBogdanyTheme = applyTheme;
  window.bogdanyCurrentThemeHex = savedHex;
  window.BOGDANY_COLOR_PRESETS = COLOR_PRESETS;
})();
