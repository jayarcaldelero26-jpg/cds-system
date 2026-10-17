import assert from 'node:assert/strict';
import test from 'node:test';
import { createCanvas, DOMMatrix, ImageData, loadImage, Path2D } from '@napi-rs/canvas';
import { renderImageThumbnail, renderPdfFirstPage } from '../../resources/js/Utils/archiveThumbnailRuntime.mjs';

globalThis.DOMMatrix ||= DOMMatrix;
globalThis.ImageData ||= ImageData;
globalThis.Path2D ||= Path2D;

// The production PDF.js browser build uses Uint8Array#toHex in this Node-only
// rendering fixture; provide the small encoding primitive absent in this Node runtime.
if (typeof Uint8Array.prototype.toHex !== 'function') {
    Object.defineProperty(Uint8Array.prototype, 'toHex', {
        configurable: true,
        value() { return Array.from(this, byte => byte.toString(16).padStart(2, '0')).join(''); },
    });
}
if (typeof Map.prototype.getOrInsertComputed !== 'function') {
    Object.defineProperty(Map.prototype, 'getOrInsertComputed', {
        configurable: true,
        value(key, callback) {
            if (this.has(key)) return this.get(key);
            const value = callback(key);
            this.set(key, value);
            return value;
        },
    });
}

function pdfWithFirstPageRectangle() {
    const content = '1 0 0 rg 20 20 160 80 re f\n';
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 120] /Resources << >> /Contents 4 0 R >>',
        `<< /Length ${content.length} >>\nstream\n${content}endstream`,
    ];
    let source = '%PDF-1.4\n';
    const offsets = [0];
    for (let index = 0; index < objects.length; index += 1) {
        offsets.push(new TextEncoder().encode(source).length);
        source += `${index + 1} 0 obj\n${objects[index]}\nendobj\n`;
    }
    const xrefOffset = new TextEncoder().encode(source).length;
    source += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    for (const offset of offsets.slice(1)) source += `${String(offset).padStart(10, '0')} 00000 n \n`;
    source += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;
    return new Blob([source], { type: 'application/pdf' });
}

test('PDF.js parses and renders a genuine first-page test PDF into a bounded thumbnail', async () => {
    const thumb = await renderPdfFirstPage(pdfWithFirstPageRectangle(), undefined, () => createCanvas(1, 1));
    assert.equal(thumb.type, 'image/jpeg');
    assert.ok(thumb.size > 100);

    const image = await loadImage(Buffer.from(await thumb.arrayBuffer()));
    assert.ok(image.width <= 320);
    assert.ok(image.height <= 180);
    const canvas = createCanvas(image.width, image.height);
    const context = canvas.getContext('2d');
    context.drawImage(image, 0, 0);
    const [red, green, blue] = context.getImageData(Math.floor(image.width / 2), Math.floor(image.height / 2), 1, 1).data;
    assert.ok(red > 180 && green < 90 && blue < 90, `first-page red region was rendered (pixel ${red},${green},${blue})`);
});

test('a genuine PNG photo is decoded and downsampled with its aspect ratio preserved', async () => {
    const previousBitmap = globalThis.createImageBitmap;
    const previousDocument = globalThis.document;
    const source = createCanvas(1000, 500);
    const sourceContext = source.getContext('2d');
    sourceContext.fillStyle = '#1f7a3c';
    sourceContext.fillRect(0, 0, source.width, source.height);
    const sourceBlob = new Blob([source.toBuffer('image/png')], { type: 'image/png' });
    let decodeOptions = null;
    globalThis.createImageBitmap = async (blob, options) => {
        decodeOptions = options;
        const image = await loadImage(Buffer.from(await blob.arrayBuffer()));
        const decoded = createCanvas(options.resizeWidth, options.resizeHeight);
        decoded.getContext('2d').drawImage(image, 0, 0, options.resizeWidth, options.resizeHeight);
        return decoded;
    };
    globalThis.document = { createElement: name => {
        assert.equal(name, 'canvas');
        return createCanvas(1, 1);
    } };
    try {
        const thumb = await renderImageThumbnail(sourceBlob, 'image/png');
        const image = await loadImage(Buffer.from(await thumb.arrayBuffer()));
        assert.equal(decodeOptions.resizeWidth, 320);
        assert.equal(decodeOptions.resizeHeight, 160);
        assert.equal(image.width / image.height, 2);
        const canvas = createCanvas(image.width, image.height);
        const context = canvas.getContext('2d');
        context.drawImage(image, 0, 0);
        const [red, green, blue] = context.getImageData(20, 20, 1, 1).data;
        assert.ok(green > red && green > blue, 'the original image color is visible in the rendered thumbnail');
    } finally {
        if (previousBitmap === undefined) delete globalThis.createImageBitmap;
        else globalThis.createImageBitmap = previousBitmap;
        if (previousDocument === undefined) delete globalThis.document;
        else globalThis.document = previousDocument;
    }
});
