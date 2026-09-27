// Copies the OCR engine (tesseract.js) into public/vendor/tesseract so invoice reading runs
// entirely from our own server — no CDN, and the photo never leaves the device.
import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';

const out = 'public/vendor/tesseract';
const files = {
    'node_modules/tesseract.js/dist/worker.min.js': 'worker.min.js',
    // LSTM-only builds (oem 1); the worker picks the one the device supports.
    'node_modules/tesseract.js-core/tesseract-core-lstm.wasm.js': 'core/tesseract-core-lstm.wasm.js',
    'node_modules/tesseract.js-core/tesseract-core-simd-lstm.wasm.js': 'core/tesseract-core-simd-lstm.wasm.js',
    'node_modules/tesseract.js-core/tesseract-core-relaxedsimd-lstm.wasm.js': 'core/tesseract-core-relaxedsimd-lstm.wasm.js',
    'node_modules/@tesseract.js-data/ara/4.0.0_best_int/ara.traineddata.gz': 'lang/ara.traineddata.gz',
    'node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz': 'lang/eng.traineddata.gz',
};

for (const [from, to] of Object.entries(files)) {
    const target = join(out, to);
    mkdirSync(dirname(target), { recursive: true });
    copyFileSync(from, target);
}

console.log(`OCR assets copied to ${out}`);
