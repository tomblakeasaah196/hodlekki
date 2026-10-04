# Vendored front-end libraries

Every runtime dependency of the Special Events module is committed here. The
module **never** loads a library from a third-party CDN (guide §8.6.1): venue
Wi-Fi is unreliable and the CSP in §19.7 only allows `'self'`. The single
exception is Google Fonts (CSS + font files), which the CSP permits explicitly.

## Why the paths carry the version

`assets/se/.htaccess` serves `vendor/**` with
`Cache-Control: public, max-age=31536000, immutable`. That is only safe when a
URL's bytes never change, so the **version is part of the path**
(`vendor/preact-10.27.2/preact.module.js`). Upgrading a library means a new
directory and an edited import map — never a changed file at an existing URL.

## How to verify or re-vendor

Files come from the npm registry tarball of the exact version below
(`https://registry.npmjs.org/<pkg>/-/<name>-<version>.tgz`), copied without
modification. To check the tree against this table:

```bash
cd assets/se/vendor
sha256sum -c VENDOR.sha256
```

To add or upgrade a library: download the tarball, copy the file into a new
versioned directory with its `LICENSE`, update the import map in
`e/index.php` and `modules/special_events/index.php`, update this file and
regenerate `VENDOR.sha256`.

## Contents

| File | Package | Version | Source path in tarball | Licence | Bytes | SHA-256 |
|---|---|---|---|---|---|---|
| `preact-10.27.2/preact.module.js` | `preact` | 10.27.2 | `dist/preact.module.js` | MIT | 11,581 | `a1cefabf06ec626adcb92731537e1e04fd09a7908e22551bab50540106dc950d` |
| `preact-10.27.2/hooks.module.js` | `preact` | 10.27.2 | `hooks/dist/hooks.module.js` | MIT | 3,753 | `9295b344df14b5395a612fed63350619d029e91cc2e80e9a2a5f920e38b88972` |
| `preact-10.27.2/signals-core.module.js` | `@preact/signals-core` | 1.12.1 | `dist/signals-core.module.js` | MIT | 4,733 | `a2261b3791bb800e7b268783e459a362f5da84f9c038be9b9509f3a6c632ad34` |
| `preact-10.27.2/signals.module.js` | `@preact/signals` | 2.3.1 | `dist/signals.module.js` | MIT | 4,362 | `ba5f10f77f2f568e20c641709b1c00a552e71bec5244fea76d1efd24100233a0` |
| `htm-3.1.1/htm.module.js` | `htm` | 3.1.1 | `dist/htm.module.js` | Apache-2.0 | 1,207 | `ab33dd3f38059b9be4d5f5350128eefb2356639c4e0bbe9d9e8b3ba75847e9e4` |
| `qrcode-generator-1.5.0/qrcode.mjs` | `qrcode-generator` | 1.5.0 | `qrcode.mjs` | MIT | 55,435 | `e4e021710660c0426e4bad9436a96dafa688b50ec7e0c4dbd773ba18108de5b8` |
| `gsap-3.13.0/gsap.min.js` | `gsap` | 3.13.0 | `dist/gsap.min.js` | GSAP Standard (no charge) | 72,435 | `96c01b81f44a3290e2b4532f55e2c9534b2adc43273a19f3756b2cb41f0fd0b6` |
| `gsap-3.13.0/ScrollTrigger.min.js` | `gsap` | 3.13.0 | `dist/ScrollTrigger.min.js` | GSAP Standard (no charge) | 44,157 | `308219390e5e3b84cda0c481e70caa9820883ae10bda44e6e9a149a81aac4b3f` |
| `gsap-3.13.0/SplitText.min.js` | `gsap` | 3.13.0 | `dist/SplitText.min.js` | GSAP Standard (no charge) | 7,247 | `5e519ea2470faa15ca4d4f27e9263e5906c07c034a9b4771164dee108136563a` |
| `canvas-confetti-1.9.3/confetti.module.mjs` | `canvas-confetti` | 1.9.3 | `dist/confetti.module.mjs` | ISC | 24,916 | `b2ab129d4c41b087ffef9dccb0e7b92e8b47df5871d3e0c1bd2d23d9b8c0cdbe` |
| `chartjs-4.5.0/chart.umd.min.js` | `chart.js` | 4.5.0 | `dist/chart.umd.min.js` | MIT | 208,341 | `2f27bcf471b2d69dd78494f6e2172fb28470eb843820e2f96bb85d39f9618d30` |
| `es-module-shims-2.8.4/es-module-shims.js` | `es-module-shims` | 2.8.4 | `dist/es-module-shims.js` | MIT | 82,102 | `fe620c5662f8bd9f46e4aa29ceae0a2eb52a031573c10598bf70f2e50a7b3d7c` |

## Licences

Each directory holds the upstream `LICENSE` beside the code.

- **preact / @preact/signals / @preact/signals-core / chart.js /
  es-module-shims / qrcode-generator** — MIT.
- **htm** — Apache-2.0 (full text in `htm-3.1.1/LICENSE`).
- **canvas-confetti** — ISC.
- **gsap** — GSAP Standard "no charge" licence. Since 2025 every GSAP plugin,
  including ScrollTrigger and SplitText, is covered at no charge. The terms are
  summarised in `gsap-3.13.0/LICENSE`; the authoritative text is at
  <https://gsap.com/standard-license/>.
- **qrcode-generator** — the package ships no `LICENSE` file; the MIT grant in
  `qrcode-generator-1.5.0/LICENSE` is reproduced from the copyright header of
  `qrcode.mjs` and the `license` field of its `package.json`.

## First-load weight (§13.15 budget: ≤ 90 KB gzipped)

| Group | Raw | Loaded by |
|---|---|---|
| Preact + hooks + signals + signals-core + htm | ~25 KB | every surface |
| GSAP + ScrollTrigger + SplitText | ~124 KB | portal, stage, lobby |
| canvas-confetti | ~25 KB | lazily, on the success screen |
| qrcode-generator | ~55 KB | lazily, share kit and posters |
| chart.js | ~208 KB | Studio Insights only |
| es-module-shims | ~82 KB | only when `HTMLScriptElement.supports('importmap')` is false |

Only the first two groups are in the portal's critical path.
