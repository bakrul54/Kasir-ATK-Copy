import assert from 'node:assert/strict';
import test from 'node:test';
import {
    calculatePrintQuote,
    classifyPixels,
    createPrintCartItems,
} from '../../resources/js/print-coverage-calculator.js';

function pixelsWithCoverage(totalPixels, matchingPixels, color) {
    const pixels = new Uint8ClampedArray(totalPixels * 4);

    for (let index = 0; index < totalPixels; index++) {
        const offset = index * 4;
        const pixel = index < matchingPixels
            ? (color ? [100, 100, 116] : [30, 30, 30])
            : [255, 255, 255];

        pixels.set([...pixel, 255], offset);
    }

    return pixels;
}

function pixelsWithMixedCoverage(totalPixels, colorPixels, blackPixels, overlappingPixels = 0) {
    const pixels = new Uint8ClampedArray(totalPixels * 4);

    for (let index = 0; index < totalPixels; index++) {
        const offset = index * 4;
        const pixel = index < overlappingPixels
            ? [10, 20, 30]
            : index < overlappingPixels + colorPixels
                ? [100, 100, 116]
                : index < overlappingPixels + colorPixels + blackPixels
                    ? [30, 30, 30]
                    : [255, 255, 255];

        pixels.set([...pixel, 255], offset);
    }

    return pixels;
}

test('classifies color and black coverage using the program thresholds', () => {
    assert.equal(classifyPixels(pixelsWithCoverage(100, 1, true)).category, 'Hitam Putih');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 2, true)).category, 'Warna 10%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 10, true)).category, 'Warna 10%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 11, true)).category, 'Warna 50%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 50, true)).category, 'Warna 50%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 51, true)).category, 'Full Warna');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 15, false)).category, 'Hitam Putih');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 16, false)).category, 'Hitam 50%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 40, false)).category, 'Hitam 50%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 50, false)).category, 'Hitam 50%');
    assert.equal(classifyPixels(pixelsWithCoverage(100, 51, false)).category, 'Full Hitam');
    assert.equal(
        classifyPixels(new Uint8ClampedArray([100, 100, 115, 255])).category,
        'Hitam Putih',
    );
});

test('classifies color pages as full color when total coverage exceeds 50 percent', () => {
    assert.equal(
        classifyPixels(pixelsWithMixedCoverage(100, 12, 68)).category,
        'Full Warna',
    );
    assert.equal(
        classifyPixels(pixelsWithMixedCoverage(100, 25, 25)).category,
        'Warna 50%',
    );
    assert.equal(
        classifyPixels(pixelsWithMixedCoverage(100, 25, 26)).category,
        'Full Warna',
    );
    assert.equal(
        classifyPixels(pixelsWithMixedCoverage(100, 0, 20, 25)).category,
        'Warna 50%',
    );
});

test('uses the print product own wholesale threshold', () => {
    const categories = [
        {
            key: 'warna-10',
            name: 'Warna 10%',
            product: {
                id: '44',
                salePrice: 1500,
                promoPrice: null,
                wholesalePrice: 1200,
                wholesaleMinQty: 20,
                wholesaleGroupId: null,
                wholesaleGroupMinQty: null,
                stock: null,
                tracksStock: false,
            },
        },
    ];
    const page = { category: 'Warna 10%' };
    const standardQuote = calculatePrintQuote(Array.from({ length: 19 }, () => page), categories);
    const wholesaleQuote = calculatePrintQuote(Array.from({ length: 20 }, () => page), categories);

    assert.equal(standardQuote.rows[0].unitPrice, 1500);
    assert.equal(standardQuote.total, 28500);
    assert.equal(wholesaleQuote.rows[0].unitPrice, 1200);
    assert.equal(wholesaleQuote.total, 24000);
});

test('creates cashier transfer rows with product IDs and quantities only', () => {
    const categories = [{
        key: 'warna-10',
        name: 'Warna 10%',
        product: {
            id: '44',
            salePrice: 1500,
            promoPrice: null,
            wholesalePrice: 1200,
            wholesaleMinQty: 20,
            wholesaleGroupId: null,
            wholesaleGroupMinQty: null,
            stock: null,
            tracksStock: false,
        },
    }];

    assert.deepEqual(createPrintCartItems([
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
    ], categories), [{ product_id: '44', quantity: 2 }]);
    assert.deepEqual(
        Object.keys(createPrintCartItems([{ category: 'Warna 10%' }], categories)[0]).sort(),
        ['product_id', 'quantity'],
    );
});

test('multiplies print quantities by the confirmed number of copies', () => {
    const categories = [{
        key: 'warna-10',
        name: 'Warna 10%',
        product: {
            id: '44',
            salePrice: 1500,
            promoPrice: null,
            wholesalePrice: null,
            wholesaleMinQty: null,
            wholesaleGroupId: null,
            wholesaleGroupMinQty: null,
            stock: null,
            tracksStock: false,
        },
    }];

    assert.deepEqual(createPrintCartItems([
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
    ], categories, 4), [{ product_id: '44', quantity: 12 }]);
    assert.throws(
        () => createPrintCartItems([{ category: 'Warna 10%' }], categories, 0),
        /bilangan bulat minimal 1/,
    );
    assert.throws(
        () => createPrintCartItems(Array.from({ length: 2 }, () => ({ category: 'Warna 10%' })), categories, 2147483648),
        /melebihi batas kapasitas transaksi/,
    );
});

test('uses cashier promo and grouped wholesale prices and blocks unavailable products', () => {
    const categories = [
        {
            key: 'hitam-putih',
            name: 'Hitam Putih',
            product: {
                id: '40',
                salePrice: 1000,
                promoPrice: 900,
                wholesalePrice: 800,
                wholesaleMinQty: null,
                wholesaleGroupId: '1',
                wholesaleGroupMinQty: 10,
                stock: null,
                tracksStock: false,
            },
        },
        {
            key: 'warna-10',
            name: 'Warna 10%',
            product: {
                id: '44',
                salePrice: 1500,
                promoPrice: null,
                wholesalePrice: 1200,
                wholesaleMinQty: null,
                wholesaleGroupId: '1',
                wholesaleGroupMinQty: 10,
                stock: null,
                tracksStock: false,
            },
        },
    ];
    const quote = calculatePrintQuote([
        { category: 'Hitam Putih' },
        { category: 'Hitam Putih' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
        { category: 'Warna 10%' },
    ], categories);

    assert.equal(quote.rows[0].unitPrice, 800);
    assert.equal(quote.rows[1].unitPrice, 1200);
    assert.equal(quote.total, 11200);
    assert.deepEqual(createPrintCartItems([{ category: 'Hitam Putih' }], categories), [
        { product_id: '40', quantity: 1 },
    ]);

    const unavailableCategories = [{ key: 'warna-10', name: 'Warna 10%', product: null }];
    assert.throws(
        () => createPrintCartItems([{ category: 'Warna 10%' }], unavailableCategories),
        /belum tersedia atau tidak aktif/,
    );
});

test('rejects invalid pixel data and unknown print categories', () => {
    assert.throws(() => classifyPixels(new Uint8ClampedArray()), /Data piksel gambar tidak valid/);
    assert.throws(
        () => calculatePrintQuote([{ category: 'Tidak dikenal' }], [{ name: 'Warna 10%' }]),
        /Kategori print tidak dikenal/,
    );
});
