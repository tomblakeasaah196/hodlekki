# Vendored front-end files (assets/ui)

Everything the members' app loads is served from this site — no CDN at runtime
(docs/UI.md §1.7). Update a file only together with this list.

| File | What | Version | Licence | SHA-256 |
| ---- | ---- | ------- | ------- | ------- |
| vendor/jquery-3.7.1.min.js | jQuery, for pages in legacy mode only | 3.7.1 (code.jquery.com) | MIT | fc9a93dd241f6b045cbff0481cf4e1901becd0e12fb45166a8f17f95823f0b1a |
| fonts/inter-latin-ext.v1.woff2 | Inter (rsms/inter via @fontsource-variable/inter 5.3.0), weight axis clipped to 400–700 with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Inter.txt) | 30ac68617ed9859ec976f3dfbf6e523b4c4aecb6c2e2071d5e95c756ee93a543 |
| fonts/inter-latin.v1.woff2 | Inter (rsms/inter via @fontsource-variable/inter 5.3.0), weight axis clipped to 400–700 with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Inter.txt) | a159cb0d1a7918940521072d569c382f0ada4b67d155057ecf11a11461d8a417 |
| fonts/inter-vietnamese.v1.woff2 | Inter (rsms/inter via @fontsource-variable/inter 5.3.0), weight axis clipped to 400–700 with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Inter.txt) | f0bab4e8096d56c96963af485fe2bd108de75a6c6f945508f2c0840f13fe74bb |
| fonts/montserrat-latin-ext.v1.woff2 | Montserrat (via @fontsource-variable/montserrat 5.3.0), static 700 instance made with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Montserrat.txt) | d8cd0be3a2287424919e58452373dbf00d9851bc351fa2237bb8d1a9becd8ec7 |
| fonts/montserrat-latin.v1.woff2 | Montserrat (via @fontsource-variable/montserrat 5.3.0), static 700 instance made with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Montserrat.txt) | 4ea1073d3d94699ff121ec3ad7171ccb7670347a44eea781931f8f45725aa5da |
| fonts/montserrat-vietnamese.v1.woff2 | Montserrat (via @fontsource-variable/montserrat 5.3.0), static 700 instance made with fontTools | — | SIL OFL 1.1 (fonts/LICENSE-Montserrat.txt) | db9b8f86881d0ed63442041aa94dd7d5714b2fdab289a775cd57a190f2e9646a |
| src/icons/*.svg | Icon sources, built into icons.svg by bin/build_ui_icons.php | lucide-static 0.460.0 | ISC (src/icons/LICENSE) | per file, not pinned |
| vendor/chart.js-4.5.0/chart.umd.min.js | Chart.js, the one chart library for every screen | 4.5.0 (npm) | MIT (LICENSE.md) | 2f27bcf471b2d69dd78494f6e2172fb28470eb843820e2f96bb85d39f9618d30 |
| vendor/cropperjs-1.5.13/cropper.min.js | Cropper.js, photo cropping (profile, congregation, charis) | 1.5.13 (npm) | MIT (LICENSE) | 615835110d07d9842d1c0a995e9fc79fb4dfa8d2c1b879ff0d648570714ee1c7 |
| vendor/cropperjs-1.5.13/cropper.min.css | Cropper.js styles | 1.5.13 (npm) | MIT | 055b9c1ce54007be24408e3d02e584e82c60a9a52cd1c780e5ff08318a1d787f |
| vendor/geoapify-autocomplete-1.5.0/geocoder-autocomplete.min.js | Geoapify address autocomplete (dist/index.min.js renamed) | 1.5.0 (npm @geoapify/geocoder-autocomplete) | MIT (package.json) | 22605d61b2ec5319ad85b4dfefc1e5960a23a3b131e4db1350283d6eed461191 |
| vendor/geoapify-autocomplete-1.5.0/minimal.css | Geoapify autocomplete theme | 1.5.0 | MIT | c954079eeca36f51fb3b043a8d59567c3aec558c865901644e5ce896def67ed7 |
| vendor/html2canvas-1.4.1/html2canvas.min.js | html2canvas, card images (loaded on demand) | 1.4.1 (npm) | MIT (LICENSE) | e87e550794322e574a1fda0c1549a3c70dae5a93d9113417a429016838eab8cb |
| vendor/xlsx-0.18.5/xlsx.full.min.js | SheetJS Community, spreadsheet export/import (loaded on demand) | 0.18.5 (npm) | Apache-2.0 (LICENSE) | c9506197caf809a075b6dee1da0d36fb19da7158ffe8a88e7b0c96c5d8623c99 |
| vendor/sortablejs-1.15.6/Sortable.min.js | SortableJS, drag to reorder | 1.15.6 (npm) | MIT (LICENSE) | 6d0a831fc19b4bae851797ad3393157e861afb7862459c11226359b27e2c4337 |
| vendor/qrcode-generator-1.5.0/qrcode.mjs | qrcode-generator, QR codes drawn in the browser (copied from assets/se/vendor, same file) | 1.5.0 | MIT (LICENSE) | e4e021710660c0426e4bad9436a96dafa688b50ec7e0c4dbd773ba18108de5b8 |
