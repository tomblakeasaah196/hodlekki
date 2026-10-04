// /assets/se/js/core/html.js
//
// The shared htm + Preact tag (guide §8.6.1). Importing this everywhere
// means one htm.bind() for the whole app, so templates are cached once.

import { h } from 'preact';
import htm from 'htm';

export const html = htm.bind(h);
export { h };
