(() => {
    const container = document.getElementById('owner-qr');
    const printButton = document.getElementById('print-owner-label');
    if (!container) return;

    try {
        qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
        const code = qrcode(0, 'Q');
        code.addData(container.dataset.url);
        code.make();
        container.innerHTML = code.createSvgTag({cellSize: 4, margin: 16, scalable: true, title: 'Open owner contact details'});
        container.querySelector('svg').setAttribute('shape-rendering', 'crispEdges');
        if (printButton) {
            printButton.hidden = false;
            printButton.addEventListener('click', async () => {
                await Promise.all([...document.images].map(image => image.decode().catch(() => {})));
                window.print();
            });
        }
    } catch (error) {
        document.getElementById('qr-error').hidden = false;
    }
})();
