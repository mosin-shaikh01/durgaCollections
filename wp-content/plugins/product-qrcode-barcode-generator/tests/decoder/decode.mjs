// Round-trip decoder for the test suites. Test tooling only; never loaded by the plugin.
//
//   node decode.mjs file1.svg [file2.svg ...]
//   node decode.mjs --width=131 label.svg --zoom sheet.png ...
//
// Rasterises each SVG with resvg and decodes it with ZXing-C++ (zxing-wasm).
// By default an SVG is rasterised at 2x (Phases 4–6). "--width=PX" rasterises the
// SVG files that follow at exactly PX pixels wide (Phase 8: a label's QR code at
// its printed size at 203 or 300 dpi); "--zoom" switches back to 2x. PNG files
// (e.g. browser screenshots of printed labels) are decoded as they are.
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
let fit = { mode: 'zoom', value: 2 };

for (const arg of process.argv.slice(2)) {
  if (arg.startsWith('--width=')) {
    fit = { mode: 'width', value: Number(arg.slice(8)) };
    continue;
  }
  if (arg === '--zoom') {
    fit = { mode: 'zoom', value: 2 };
    continue;
  }

  const png = arg.toLowerCase().endsWith('.png')
    ? readFileSync(arg)
    : new Resvg(readFileSync(arg, 'utf8'), { fitTo: fit, background: '#ffffff' }).render().asPng();
  const results = await readBarcodes(new Blob([png], { type: 'image/png' }), {
    formats: ['QRCode', 'Code128'],
    tryHarder: true,
  });
  out.push({
    file: arg,
    results: results.filter((r) => r.isValid).map((r) => ({ format: r.format, text: r.text, ecLevel: r.ecLevel })),
  });
}

process.stdout.write(JSON.stringify(out));
