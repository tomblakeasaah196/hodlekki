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
