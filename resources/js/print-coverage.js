import * as pdfjs from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.mjs?url';
import { calculatePrintQuote, createPrintCartItems, classifyPixels } from './print-coverage-calculator.js';

pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const MAX_FILE_SIZE = 25 * 1024 * 1024;
const MAX_PAGE_PIXELS = 12_000_000;
const PDF_RENDER_SCALE = 100 / 72;
const formatter = new Intl.NumberFormat('id-ID');
const form = document.getElementById('print-coverage-form');
const fileInput = document.getElementById('print-coverage-file');
const submitButton = document.getElementById('print-coverage-submit');
const errorMessage = document.getElementById('print-coverage-error');
const statusMessage = document.getElementById('print-coverage-status');
const results = document.getElementById('print-coverage-results');
const optionsElement = document.getElementById('print-coverage-options');
const addToCartButton = document.getElementById('print-coverage-add-to-cart');
const transferDialog = document.getElementById('print-coverage-confirm-dialog');
const transferForm = document.getElementById('print-coverage-confirm-form');
const copyCountInput = document.getElementById('print-coverage-copy-count');
const copyCountDescription = document.getElementById('print-coverage-confirm-description');
const transferError = document.getElementById('print-coverage-confirm-error');
const cancelTransferButton = document.getElementById('print-coverage-cancel');
const printCategories = Object.entries(JSON.parse(optionsElement.dataset.categories))
    .map(([key, category]) => ({
        key,
        name: category.name,
        product: category.product,
    }));
let analyzedPages = [];

function formatCurrency(amount) {
    return `Rp ${formatter.format(amount)}`;
}

function updateCopyCountDescription() {
    const copies = copyCountInput.valueAsNumber;
    if (!Number.isSafeInteger(copies) || copies < 1) {
        copyCountDescription.textContent = 'Masukkan jumlah cetak berupa bilangan bulat minimal 1.';
        return;
    }

    const totalSheets = analyzedPages.length * copies;
    if (!Number.isSafeInteger(totalSheets)) {
        copyCountDescription.textContent = 'Jumlah lembar total terlalu besar.';
        return;
    }

    copyCountDescription.textContent =
        `${formatter.format(analyzedPages.length)} lembar × ${formatter.format(copies)} kali = ${formatter.format(totalSheets)} lembar di keranjang. Harga final dihitung ulang oleh Kasir.`;
}

function getFileExtension(file) {
    return file.name.split('.').pop().toLowerCase();
}

function createAnalysisCanvas(width, height) {
    if (width * height > MAX_PAGE_PIXELS) {
        throw new Error('Ukuran gambar terlalu besar. Batasnya 12 juta piksel per halaman.');
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d', { willReadFrequently: true });

    if (!context) {
        throw new Error('Browser tidak dapat menyiapkan kanvas untuk menganalisis gambar.');
    }

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, width, height);

    return { canvas, context };
}

function readCanvasPixels(context, width, height) {
    return classifyPixels(context.getImageData(0, 0, width, height).data);
}

async function analyzeImage(file) {
    const image = await createImageBitmap(file);

    try {
        const { canvas, context } = createAnalysisCanvas(image.width, image.height);
        context.drawImage(image, 0, 0);

        return [readCanvasPixels(context, image.width, image.height)];
    } finally {
        image.close();
    }
}

async function analyzePdf(file) {
    const loadingTask = pdfjs.getDocument({ data: await file.arrayBuffer() });

    try {
        const document = await loadingTask.promise;
        const pages = [];

        for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber++) {
            statusMessage.textContent = `Menganalisis halaman ${pageNumber} dari ${document.numPages}...`;

            const page = await document.getPage(pageNumber);
            const viewport = page.getViewport({ scale: PDF_RENDER_SCALE });
            const width = Math.ceil(viewport.width);
            const height = Math.ceil(viewport.height);
            const { canvas, context } = createAnalysisCanvas(width, height);

            await page.render({
                canvas,
                canvasContext: context,
                viewport,
                background: '#ffffff',
            }).promise;

            pages.push(readCanvasPixels(context, width, height));
            page.cleanup();
            canvas.width = 0;
            canvas.height = 0;
        }

        return pages;
    } finally {
        await loadingTask.destroy();
    }
}

function addCell(row, value, className = '') {
    const cell = document.createElement('td');

    if (className) {
        cell.className = className;
    }

    cell.textContent = value;
    row.append(cell);
}

function renderResults(file, pages) {
    const quote = calculatePrintQuote(pages, printCategories);
    const summary = document.getElementById('print-coverage-summary');
    const detail = document.getElementById('print-coverage-pages');
    const warning = document.getElementById('print-coverage-cart-warning');
    const missingProducts = quote.rows
        .filter((row) => row.count > 0 && row.product === null)
        .map((row) => row.name);
    const stockIssues = quote.rows
        .filter((row) => row.stockIssue)
        .map((row) => row.product.name);

    summary.replaceChildren();
    detail.replaceChildren();

    for (const rowData of quote.rows) {
        const row = document.createElement('tr');
        addCell(row, rowData.name);
        addCell(row, formatter.format(rowData.count), 'r');
        addCell(row, rowData.unitPrice === null ? 'Produk belum tersedia' : formatCurrency(rowData.unitPrice), 'r');
        addCell(row, rowData.subtotal === null ? '—' : formatCurrency(rowData.subtotal), 'r');
        summary.append(row);
    }

    pages.forEach((page, index) => {
        const row = document.createElement('tr');
        addCell(row, `Lembar ${index + 1}`);
        addCell(row, page.category);
        addCell(row, `${page.colorPercent.toFixed(1)}%`, 'r');
        addCell(row, `${page.blackPercent.toFixed(1)}%`, 'r');
        detail.append(row);
    });

    document.getElementById('print-coverage-filename').textContent = file.name;
    document.getElementById('print-coverage-tier').textContent = 'Harga mengikuti produk dan pengaturan Kasir';
    document.getElementById('print-coverage-page-count').textContent =
        `${formatter.format(quote.pageCount)} lembar`;
    document.getElementById('print-coverage-grand-total').textContent =
        quote.total === null ? 'Periksa produk print di Etalase' : formatCurrency(quote.total);
    document.getElementById('print-coverage-table-total').textContent =
        quote.total === null ? '—' : formatCurrency(quote.total);
    warning.hidden = quote.canAddToCart;
    warning.textContent = [
        missingProducts.length > 0
            ? `Produk berikut tidak tersedia atau tidak aktif di Etalase: ${missingProducts.join(', ')}.`
            : '',
        stockIssues.length > 0
            ? `Stok tidak mencukupi untuk: ${stockIssues.join(', ')}.`
            : '',
    ].filter(Boolean).join(' ');
    analyzedPages = pages;
    addToCartButton.hidden = !quote.canAddToCart;
    results.hidden = false;
}

addToCartButton.addEventListener('click', () => {
    copyCountInput.value = '1';
    updateCopyCountDescription();
    transferError.hidden = true;
    transferDialog.showModal();
    copyCountInput.focus();
});

copyCountInput.addEventListener('input', updateCopyCountDescription);

cancelTransferButton.addEventListener('click', () => {
    transferDialog.close();
});

transferForm.addEventListener('submit', (event) => {
    event.preventDefault();
    transferError.hidden = true;

    try {
        const items = createPrintCartItems(
            analyzedPages,
            printCategories,
            copyCountInput.valueAsNumber,
        );
        window.sessionStorage.setItem(optionsElement.dataset.transferKey, JSON.stringify(items));
        window.location.assign(optionsElement.dataset.cashierUrl);
    } catch (error) {
        console.error('Konfirmasi hasil hitung print tidak dapat diproses.', error);
        transferError.textContent = error instanceof Error
            ? `Hasil hitung tidak dapat dikirim: ${error.message}`
            : 'Hasil hitung tidak dapat dikirim. Periksa izin penyimpanan browser lalu coba lagi.';
        transferError.hidden = false;
    }
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorMessage.hidden = true;
    results.hidden = true;
    statusMessage.textContent = '';

    const file = fileInput.files[0];

    if (!file) {
        errorMessage.textContent = 'Pilih file PDF atau gambar terlebih dahulu.';
        errorMessage.hidden = false;
        return;
    }

    if (file.size > MAX_FILE_SIZE) {
        errorMessage.textContent = 'Ukuran file melebihi batas 25 MB.';
        errorMessage.hidden = false;
        return;
    }

    const extension = getFileExtension(file);

    if (!['pdf', 'jpg', 'jpeg', 'png', 'bmp', 'webp'].includes(extension)) {
        errorMessage.textContent = 'Format file tidak didukung. Pilih PDF, JPG, JPEG, PNG, BMP, atau WebP.';
        errorMessage.hidden = false;
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Sedang menganalisis...';

    try {
        statusMessage.textContent = 'Menyiapkan dokumen...';
        const pages = extension === 'pdf'
            ? await analyzePdf(file)
            : await analyzeImage(file);

        renderResults(file, pages);
        statusMessage.textContent = 'Analisis selesai.';
    } catch (error) {
        console.error('Dokumen tidak dapat dianalisis.', error);
        statusMessage.textContent = 'Analisis gagal.';
        errorMessage.textContent = error instanceof Error
            ? `Dokumen tidak dapat dianalisis: ${error.message}`
            : 'Dokumen tidak dapat dianalisis. Periksa file lalu coba lagi.';
        errorMessage.hidden = false;
    } finally {
        submitButton.disabled = false;
        submitButton.textContent = 'Hitung Coverage & Harga';
    }
});
