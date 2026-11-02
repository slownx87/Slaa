/*
 * Service worker do app instalável (PWA).
 *
 * Regra de ouro: PÁGINA PHP NUNCA É GUARDADA EM CACHE. Elas trazem dados de
 * ponto e dependem do login — servir uma cópia velha mostraria informação
 * errada, ou a tela de outro usuário. Só os arquivos fixos (logo, ícones,
 * scripts) ficam guardados, e é isso que faz o app abrir rápido.
 */
const CACHE = 'ponto-v3';

/* Arquivos fixos do app. */
const ESTATICOS = [
    'assets/tailwind.css',
    'assets/logo-vss-acj.png',
    'assets/icones/app-192.png',
    'assets/icones/app-512.png',
    'assets/tutorial.js',
    'assets/espelho-pdf.js',
    'offline.html'
];

self.addEventListener('install', (evento) => {
    evento.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        /* Um arquivo que falhe não pode derrubar a instalação inteira. */
        await Promise.all(ESTATICOS.map((url) => cache.add(url).catch(() => null)));
        self.skipWaiting();
    })());
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil((async () => {
        const nomes = await caches.keys();
        await Promise.all(nomes.filter((n) => n !== CACHE).map((n) => caches.delete(n)));
        self.clients.claim();
    })());
});

self.addEventListener('fetch', (evento) => {
    const req = evento.request;

    /* POST (bater ponto, aprovar pedido, login) sempre vai direto para a rede. */
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    const mesmaOrigem = url.origin === self.location.origin;
    const ehPagina = req.mode === 'navigate' || url.pathname.endsWith('.php');

    /* Páginas: sempre da rede. Sem internet, mostra o aviso de offline. */
    if (ehPagina) {
        evento.respondWith((async () => {
            try {
                return await fetch(req);
            } catch (e) {
                const cache = await caches.open(CACHE);
                const offline = await cache.match('offline.html');
                return offline || new Response(
                    '<h1>Sem conexão</h1><p>Verifique a internet e tente de novo.</p>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
                );
            }
        })());
        return;
    }

    /* Arquivos fixos: usa o guardado e atualiza por baixo. */
    evento.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const guardado = await cache.match(req);

        const daRede = fetch(req).then((resp) => {
            /* Guarda só o que deu certo; da CDN vem "opaque", que também serve. */
            if (resp && (resp.ok || resp.type === 'opaque') && mesmaOrigem) {
                cache.put(req, resp.clone()).catch(() => {});
            }
            return resp;
        }).catch(() => null);

        return guardado || (await daRede) || new Response('', { status: 504 });
    })());
});
