// Round-trip decoder for tests/phase4-rendering.php. Test tooling only; never loaded by the plugin.
//
//   node decode.mjs file1.svg [file2.svg ...]
//
// Rasterises each SVG with resvg (at 2x) and decodes it with ZXing-C++ (zxing-wasm).
// Prints one JSON array: [{ "file": ..., "results": [{ "format", "text", "ecLevel" }] }, ...]
//
// The decoder's .wasm is loaded from node_modules. By default zxing-wasm would
// download it from a CDN at runtime; this keeps the tests offline and pinned.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';
import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';

const wasmPath = fileURLToPath(import.meta.resolve('zxing-wasm/reader/zxing_reader.wasm'));
await prepareZXingModule({ overrides: { wasmBinary: readFileSync(wasmPath) }, fireImmediately: true });

const out = [];

for (const file of process.argv.slice(2)) {
  const svg = readFileSync(file, 'utf8');
  const png = new Resvg(svg, { fitTo: { mode: 'zoom', value: 2 }, background: '#ffffff' }).render().asPng();
  const results = await readBarcodes(new Blob([png], { type: 'image/png' }), {
    formats: ['QRCode', 'Code128'],
    tryHarder: true,
  });
  out.push({
    file,
    results: results.filter((r) => r.isValid).map((r) => ({ format: r.format, text: r.text, ecLevel: r.ecLevel })),
  });
}

process.stdout.write(JSON.stringify(out));
