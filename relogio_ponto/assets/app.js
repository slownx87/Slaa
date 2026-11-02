(function () {
    function pad(n) { return String(n).padStart(2, '0'); }
    function tickClock() {
        const clock = document.querySelector('[data-live-clock]');
        const dateLine = document.querySelector('[data-date-line]');
        if (!clock) return;
        const d = new Date();
        clock.textContent = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
        if (dateLine) {
            dateLine.textContent = d.toLocaleDateString('pt-BR', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
        }
    }
    setInterval(tickClock, 1000);
    tickClock();

    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    const preview = document.getElementById('photoPreview');
    const hidden = document.getElementById('foto_base64');
    const btnStart = document.getElementById('btnStartCamera');
    const btnCapture = document.getElementById('btnCapturePhoto');
    let stream = null;

    async function startCamera() {
        if (!video) return;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            video.srcObject = stream;
            await video.play();
        } catch (e) {
            alert('Não foi possível abrir a câmera. Verifique a permissão do navegador e use HTTPS.');
        }
    }

    function capturePhoto() {
        if (!video || !canvas || !hidden) return false;
        if (!video.videoWidth) {
            alert('Abra a câmera antes de capturar a foto.');
            return false;
        }
        const maxW = 900;
        const ratio = video.videoHeight / video.videoWidth;
        canvas.width = maxW;
        canvas.height = Math.round(maxW * ratio);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        const agora = new Date();
        const selo = 'Vss E Acj - Foto do ponto - ' + agora.toLocaleString('pt-BR');
        ctx.font = Math.max(22, Math.round(canvas.width * 0.026)) + 'px Arial';
        const metrics = ctx.measureText(selo);
        const boxH = Math.max(44, Math.round(canvas.height * 0.07));
        ctx.fillStyle = 'rgba(0,0,0,0.62)';
        ctx.fillRect(0, canvas.height - boxH, canvas.width, boxH);
        ctx.fillStyle = '#ffffff';
        ctx.fillText(selo, 18, canvas.height - Math.round(boxH * 0.33));

        const data = canvas.toDataURL('image/jpeg', 0.78);
        hidden.value = data;
        if (preview) {
            preview.src = data;
            preview.style.display = 'block';
        }
        return true;
    }

    if (btnStart) btnStart.addEventListener('click', function (e) { e.preventDefault(); startCamera(); });
    if (btnCapture) btnCapture.addEventListener('click', function (e) { e.preventDefault(); capturePhoto(); });

    document.querySelectorAll('[data-ponto-tipo]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const form = btn.closest('form');
            const tipoInput = form ? form.querySelector('input[name="tipo"]') : null;
            const fotoInput = form ? form.querySelector('input[name="foto_base64"]') : null;
            if (tipoInput) tipoInput.value = btn.getAttribute('data-ponto-tipo');
            if (fotoInput && !fotoInput.value) {
                e.preventDefault();
                alert('Capture a foto de agora antes de bater o ponto.');
                return false;
            }
            if (!confirm('Confirmar batida: ' + btn.textContent.trim() + '?')) {
                e.preventDefault();
                return false;
            }
        });
    });

    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            const msg = el.getAttribute('data-confirm') || 'Confirmar?';
            if (!confirm(msg)) e.preventDefault();
        });
    });
})();
