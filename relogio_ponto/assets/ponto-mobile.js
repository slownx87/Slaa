(() => {
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const app = $('#pontoApp');
    const csrf = app ? app.dataset.csrf : '';

    const actionText = {
        entrada: 'Registrar entrada e iniciar a jornada.',
        saida_almoco: 'Registrar pausa para almoço.',
        volta_almoco: 'Registrar retorno do almoço.',
        saida_janta: 'Registrar pausa para janta.',
        volta_janta: 'Registrar retorno da janta.',
        saida_descanso: 'Registrar saída para descanso.',
        volta_descanso: 'Registrar volta do descanso.',
        saida: 'Finalizar o expediente de hoje.'
    };

    const actionIcon = {
        entrada: '▶',
        saida_almoco: '🍽',
        volta_almoco: '↩',
        saida_janta: '☕',
        volta_janta: '↩',
        saida_descanso: '⏸',
        volta_descanso: '↩',
        saida: '⏻'
    };

    let currentPin = '';
    let currentFuncionario = null;
    let photoBase64 = '';
    let lat = '';
    let lng = '';
    let stream = null;

    function pad(n) { return String(n).padStart(2, '0'); }

    function formatDate(date = new Date()) {
        const txt = date.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
        return txt.charAt(0).toUpperCase() + txt.slice(1);
    }

    function setClock() {
        const d = new Date();
        const time = $('#currentTime');
        const date = $('#currentDate');
        if (time) time.textContent = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        if (date) date.textContent = formatDate(d);
    }

    function toast(message, type = '') {
        const box = $('#toast');
        if (!box) return;
        box.textContent = message;
        box.className = `toast show ${type}`;
        clearTimeout(box._timer);
        box._timer = setTimeout(() => { box.className = 'toast'; }, 4200);
    }

    function apiPost(data) {
        const body = new URLSearchParams();
        body.append('ajax', '1');
        body.append('csrf_token', csrf || '');
        Object.keys(data || {}).forEach((key) => body.append(key, data[key] == null ? '' : String(data[key])));

        return fetch('bater-ponto.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body,
            credentials: 'same-origin'
        }).then(async (response) => {
            const text = await response.text();
            let json = null;
            try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
            if (!response.ok || !json || json.ok === false) {
                const fallback = text ? text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180) : '';
                console.error('Erro na chamada bater-ponto.php:', response.status, text);
                throw new Error(json && json.error ? json.error : (fallback || 'Não foi possível concluir a solicitação.'));
            }
            return json;
        });
    }

    function resetPin() {
        currentPin = '';
        renderPinDots();
    }

    function renderPinDots() {
        $$('#pinDots span').forEach((dot, index) => dot.classList.toggle('active', index < currentPin.length));
        const confirm = $('#confirmPin');
        if (confirm) confirm.disabled = currentPin.length < 4;
    }

    function go(screen) {
        $$('.screen').forEach((item) => item.classList.toggle('active', item.dataset.screen === screen));
        $$('.nav-item').forEach((item) => {
            const goTo = item.dataset.go;
            const mode = item.dataset.openMode;
            const active = (screen === 'home' && goTo === 'home') || (screen === 'pin' && mode === 'point') || (screen === 'actions' && mode === 'point');
            item.classList.toggle('active', active);
        });
        if (screen === 'pin') resetPin();
        if (screen === 'home') renderHome(currentFuncionario);
    }

    function renderHome(funcionario) {
        const employeeName = $('#employeeName');
        const workPlace = $('#workPlace');
        const journeyStatus = $('#journeyStatus');
        const nextActionText = $('#nextActionText');
        const orb = $('#statusOrb');

        if (employeeName) employeeName.textContent = funcionario ? funcionario.nome : 'Funcionário';
        if (workPlace) workPlace.textContent = funcionario ? (funcionario.condominio || 'Vss E Acj') : 'Vss E Acj';
        if (journeyStatus) journeyStatus.textContent = funcionario && funcionario.status ? funcionario.status.label : 'Fora do expediente';
        if (nextActionText) nextActionText.textContent = funcionario && funcionario.status ? funcionario.status.next : 'Digite seu PIN para registrar seu ponto.';

        if (orb) {
            orb.className = 'status-orb';
            if (!funcionario || !funcionario.status || funcionario.status.key === 'off') orb.classList.add('off');
            if (funcionario && funcionario.status && ['lunch', 'dinner', 'break'].includes(funcionario.status.key)) orb.classList.add('pause');
            if (funcionario && funcionario.status && funcionario.status.key === 'ended') orb.classList.add('ended');
        }
    }

    function actionFullLabel(tipo) {
        const map = {
            entrada: 'Iniciar trabalho',
            saida_almoco: 'Sair para almoço',
            volta_almoco: 'Voltar do almoço',
            saida_janta: 'Sair para janta',
            volta_janta: 'Voltar da janta',
            saida_descanso: 'Sair para descanso',
            volta_descanso: 'Voltar do descanso',
            saida: 'Sair do trabalho'
        };
        return map[tipo] || tipo;
    }

    function renderActions(funcionario) {
        currentFuncionario = funcionario;
        renderHome(funcionario);
        const title = $('#actionTitle');
        const help = $('#actionHelp');
        if (title) title.textContent = funcionario.nome;
        if (help) help.textContent = `${funcionario.cargo ? funcionario.cargo + ' • ' : ''}${funcionario.condominio || 'Vss E Acj'}`;

        const actions = funcionario.actions || {};
        const actionList = $('#actionList');
        const entries = Object.entries(actions);
        if (!actionList) return;

        if (!entries.length) {
            actionList.innerHTML = '<div class="empty-state">Ponto de hoje já foi finalizado.</div>';
        } else {
            actionList.innerHTML = entries.map(([tipo, label]) => `
                <button class="action-card" type="button" data-action="${tipo}">
                    <span class="action-icon">${actionIcon[tipo] || '✓'}</span>
                    <span><strong>${label}</strong><small>${actionText[tipo] || 'Registrar ponto.'}</small></span>
                    <span class="action-arrow">›</span>
                </button>
            `).join('');
        }

        // Clique das ações fica no bindEvents por delegação, para não perder chamada
        // quando a lista é recriada dinamicamente.
        go('actions');
    }

    async function identify() {
        const label = $('#confirmPin');
        if (label) {
            label.disabled = true;
            label.textContent = 'Identificando...';
        }
        try {
            const data = await apiPost({ acao: 'identificar', pin: currentPin });
            renderActions(data.funcionario);
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            if (label) {
                label.disabled = currentPin.length < 4;
                label.textContent = 'Confirmar PIN';
            }
        }
    }

    async function startCamera() {
        const video = $('#cameraVideo');
        if (!video) return;
        try {
            if (stream) return;
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
            video.srcObject = stream;
            video.classList.add('active');
            await video.play();
        } catch (error) {
            toast('Não foi possível abrir a câmera. Use HTTPS e permita a câmera.', 'error');
        }
    }

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        const video = $('#cameraVideo');
        if (video) {
            video.pause();
            video.srcObject = null;
            video.classList.remove('active');
        }
    }

    function capturePhoto() {
        const video = $('#cameraVideo');
        const canvas = $('#cameraCanvas');
        const preview = $('#photoPreview');
        if (!video || !canvas || !video.videoWidth) {
            toast('Abra a câmera antes de capturar a foto.', 'error');
            return false;
        }
        const maxW = 900;
        const ratio = video.videoHeight / video.videoWidth;
        canvas.width = maxW;
        canvas.height = Math.round(maxW * ratio);
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        const stamp = 'Vss E Acj - Ponto - ' + new Date().toLocaleString('pt-BR');
        const boxH = Math.max(44, Math.round(canvas.height * 0.07));
        ctx.fillStyle = 'rgba(0,0,0,.62)';
        ctx.fillRect(0, canvas.height - boxH, canvas.width, boxH);
        ctx.fillStyle = '#fff';
        ctx.font = Math.max(22, Math.round(canvas.width * .026)) + 'px Arial';
        ctx.fillText(stamp, 18, canvas.height - Math.round(boxH * .33));

        photoBase64 = canvas.toDataURL('image/jpeg', 0.78);
        preview.src = photoBase64;
        preview.classList.add('active');
        const photoStatus = $('#photoStatus');
        if (photoStatus) {
            photoStatus.textContent = 'capturada';
            photoStatus.classList.add('ok');
        }
        toast('Foto capturada.', 'success');
        return true;
    }

    async function registerPoint(tipo) {
        if (!currentFuncionario) {
            toast('Digite o PIN novamente para registrar.', 'error');
            go('pin');
            return;
        }
        if (!photoBase64) {
            if (!capturePhoto()) return;
        }
        if (!confirm('Confirmar batida: ' + actionFullLabel(tipo) + '?')) return;

        const button = $(`[data-action="${tipo}"]`);
        if (button) button.disabled = true;
        try {
            const data = await apiPost({
                acao: 'registrar',
                pin: currentPin,
                condominio_id: String(currentFuncionario.condominio_id || 0),
                tipo,
                foto_base64: photoBase64,
                latitude: lat,
                longitude: lng,
                observacao: ($('#observacao') && $('#observacao').value) || ''
            });

            stopCamera();
            photoBase64 = '';
            const preview = $('#photoPreview');
            const photoStatus = $('#photoStatus');
            const observacao = $('#observacao');
            if (preview) preview.classList.remove('active');
            if (photoStatus) {
                photoStatus.textContent = 'pendente';
                photoStatus.classList.remove('ok');
            }
            if (observacao) observacao.value = '';

            currentFuncionario = data.funcionario;
            $('#successTitle').textContent = data.receipt.label + ' registrada';
            $('#successText').textContent = data.message || 'Sua jornada foi atualizada no sistema.';
            $('#receiptType').textContent = data.receipt.label;
            $('#receiptTime').textContent = data.receipt.hora;
            $('#receiptPlace').textContent = data.receipt.local || 'Vss E Acj';
            toast(data.message, 'success');
            go('success');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    function bindEvents() {
        $$('[data-go]').forEach((button) => button.addEventListener('click', () => go(button.dataset.go)));
        $$('[data-open-mode]').forEach((button) => {
            button.addEventListener('click', () => {
                go('pin');
            });
        });
        const keypad = $('#keypad');
        if (keypad) {
            keypad.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (!button) return;
                if (button.dataset.key && currentPin.length < 8) currentPin += button.dataset.key;
                if (button.hasAttribute('data-del')) currentPin = currentPin.slice(0, -1);
                if (button.hasAttribute('data-clear')) currentPin = '';
                renderPinDots();
            });
        }
        const actionList = $('#actionList');
        if (actionList) {
            actionList.addEventListener('click', (event) => {
                const button = event.target.closest('[data-action]');
                if (!button || button.disabled) return;
                registerPoint(button.dataset.action);
            });
        }

        const confirmPin = $('#confirmPin');
        const start = $('#btnStartCamera');
        const capture = $('#btnCapturePhoto');
        if (confirmPin) confirmPin.addEventListener('click', identify);
        if (start) start.addEventListener('click', startCamera);
        if (capture) capture.addEventListener('click', capturePhoto);
        window.addEventListener('pagehide', stopCamera);
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition((pos) => {
            lat = String(pos.coords.latitude);
            lng = String(pos.coords.longitude);
        }, () => {}, { enableHighAccuracy: false, timeout: 5000, maximumAge: 300000 });
    }

    setClock();
    setInterval(setClock, 1000);
    bindEvents();
    renderHome(null);
})();
