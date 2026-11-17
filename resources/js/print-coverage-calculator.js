export function classifyPixels(pixels) {
    if (pixels.length === 0 || pixels.length % 4 !== 0) {
        throw new Error('Data piksel gambar tidak valid.');
    }

    let coloredPixels = 0;
    let blackPixels = 0;
    let coveredPixels = 0;
    const totalPixels = pixels.length / 4;

    for (let index = 0; index < pixels.length; index += 4) {
        const red = pixels[index];
        const green = pixels[index + 1];
        const blue = pixels[index + 2];
        const isColoredPixel = (
            Math.abs(red - green) > 15
            || Math.abs(red - blue) > 15
            || Math.abs(green - blue) > 15
        );
        const isBlackPixel = red < 50 && green < 50 && blue < 50;

        if (isColoredPixel) {
            coloredPixels++;
        }

        if (isBlackPixel) {
            blackPixels++;
        }

        if (isColoredPixel || isBlackPixel) {
            coveredPixels++;
        }
    }

    const colorPercent = (coloredPixels / totalPixels) * 100;
    const blackPercent = (blackPixels / totalPixels) * 100;
    const totalCoveragePercent = (coveredPixels / totalPixels) * 100;
    let category;

    if (colorPercent >= 2) {
        if (totalCoveragePercent > 50) {
            category = 'Full Warna';
        } else if (colorPercent > 10) {
            category = 'Warna 50%';
        } else {
            category = 'Warna 10%';
        }
    } else if (blackPercent > 50) {
        category = 'Full Hitam';
    } else if (blackPercent > 15) {
        category = 'Hitam 50%';
    } else {
        category = 'Hitam Putih';
    }

    return { colorPercent, blackPercent, category };
}

export function calculatePrintQuote(pages, categories) {
    if (!Array.isArray(categories) || categories.length === 0) {
        throw new Error('Daftar produk print belum tersedia.');
    }

    if (pages.length === 0) {
        throw new Error('Dokumen tidak memiliki halaman untuk dihitung.');
    }

    const counts = new Map(categories.map(({ name }) => [name, 0]));

    for (const page of pages) {
        if (!counts.has(page.category)) {
            throw new Error(`Kategori print tidak dikenal: ${page.category}`);
        }

        counts.set(page.category, counts.get(page.category) + 1);
    }

    const groupQuantities = new Map();

    for (const { name, product } of categories) {
        if (!product || product.wholesaleGroupId === null) {
            continue;
        }

        groupQuantities.set(
            product.wholesaleGroupId,
            (groupQuantities.get(product.wholesaleGroupId) || 0) + counts.get(name),
        );
    }

    const rows = categories.map(({ key, name, product }) => {
        const count = counts.get(name);

        if (!product) {
            return { key, name, count, unitPrice: null, subtotal: null, product: null, stockIssue: false };
        }

        const prices = [product.salePrice];
        if (product.promoPrice !== null) {
            prices.push(product.promoPrice);
        }

        const isGrouped = product.wholesaleGroupId !== null
            && product.wholesaleGroupMinQty !== null;
        const minimumQuantity = isGrouped
            ? product.wholesaleGroupMinQty
            : product.wholesaleMinQty;
        const eligibleQuantity = isGrouped
            ? groupQuantities.get(product.wholesaleGroupId) || 0
            : count;

        if (
            product.wholesalePrice !== null
            && minimumQuantity !== null
            && eligibleQuantity >= minimumQuantity
        ) {
            prices.push(product.wholesalePrice);
        }

        const unitPrice = Math.min(...prices);

        return {
            key,
            name,
            count,
            unitPrice,
            subtotal: count * unitPrice,
            product,
            stockIssue: product.tracksStock && product.stock !== null && count > product.stock,
        };
    });
    const isComplete = rows.every((row) => row.count === 0 || row.product !== null);
    const hasStockIssues = rows.some((row) => row.stockIssue);

    return {
        pageCount: pages.length,
        rows,
        isComplete,
        canAddToCart: isComplete && !hasStockIssues,
        total: isComplete ? rows.reduce((total, row) => total + row.subtotal, 0) : null,
    };
}

export function createPrintCartItems(pages, categories, copies = 1) {
    if (!Number.isSafeInteger(copies) || copies < 1) {
        throw new Error('Jumlah cetak harus berupa bilangan bulat minimal 1.');
    }

    const quote = calculatePrintQuote(pages, categories);

    if (!quote.canAddToCart) {
        const missingProducts = quote.rows
            .filter((row) => row.count > 0 && row.product === null)
            .map((row) => row.name);
        const stockIssues = quote.rows
            .filter((row) => row.stockIssue)
            .map((row) => row.product.name);

        const reasons = [];
        if (missingProducts.length > 0) {
            reasons.push(`produk belum tersedia atau tidak aktif: ${missingProducts.join(', ')}`);
        }
        if (stockIssues.length > 0) {
            reasons.push(`stok tidak mencukupi: ${stockIssues.join(', ')}`);
        }

        throw new Error(`Hasil tidak bisa dimasukkan ke keranjang karena ${reasons.join('; ')}.`);
    }

    return quote.rows
        .filter((row) => row.count > 0)
        .map((row) => {
            const quantity = row.count * copies;
            if (!Number.isSafeInteger(quantity) || quantity > 4294967295) {
                throw new Error('Jumlah lembar melebihi batas kapasitas transaksi.');
            }

            return {
                product_id: row.product.id,
                quantity,
            };
        });
}
