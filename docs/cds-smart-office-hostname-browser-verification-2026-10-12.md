# CDS-SMART Office Hostname and Browser Verification

**Date:** 2026-10-12

## Hostname result

The hostname resolved to one IPv4 address matching a local interface; no IPv6 address was returned. Apache has an IPv4 port-80 listener. Its parsed vhost is cds-smart (cds-smart.conf), and that vhost's DocumentRoot matches this checkout's public directory.

| Request path | Root | Login |
| --- | --- | --- |
| curl default, proxy environment enabled | HTTP 000, curl exit 7; DNS 0.000027 s, connect/first byte 0.000 s, total 2.012 s | HTTP 000, curl exit 7; DNS 0.000029 s, connect/first byte 0.000 s, total 2.035 s |
| Hostname with request-only --noproxy * | HTTP 200; DNS 0.005105 s, connect 0.005353 s, first byte 0.264221 s, total 0.265107 s | HTTP 200; DNS 0.004148 s, connect 0.004356 s, first byte 0.054331 s, total 0.054983 s |
| Loopback with Host: cds-smart | HTTP 200; DNS 0.000021 s, connect 0.000606 s, first byte 0.251440 s, total 0.252276 s | HTTP 200; DNS 0.000023 s, connect 0.000562 s, first byte 0.048306 s, total 0.048934 s |

The verbose root trace showed curl selected its http_proxy environment setting. The proxy-enabled requests failed before HTTP, so they had no redirect response; direct hostname and loopback responses had no redirect. The earlier direct-hostname timeout did not reproduce with the per-request proxy bypass; its cause remains unknown.

The public build manifest returned HTTP 200 through hostname and loopback (71,303 bytes) and matched the workspace manifest, confirming the direct hostname route reaches this checkout. No proxy or network settings were changed.

## Browser coverage

The computer-use browser surface could not start (CreateProcessWithLogonW failed: 1056). No authenticated browser session, UI, console, or browser network activity was observed. Browser review is **not verified**.

Historical Reports filters, module search/selection, Encode chooser and calendar; existing-row details and protected preview (if an eligible row exists); Office TAT units, periods and participant explanations; narrow viewport, theme and keyboard focus remain unobserved. No reports or records were changed, and no screenshots were retained.

Manual handoff:
1. Open Settings → Historical Reports; test shared filters, search/select and clear a module, inspect the Encode chooser/calendar, then cancel.
2. Open Administration → Office TAT; switch units and assessment periods and inspect participant details when available.
3. If historical rows exist, open/close details and protected preview; otherwise leave those checks pending.
