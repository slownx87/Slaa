/* Tema do Tailwind deste projeto.
 *
 * Este arquivo NAO e carregado pelo app: o app usa assets/tailwind.css, que ja
 * vem compilado. Ele existe porque a compilacao (ferramentas/gerar-css.py)
 * precisa saber as cores, tamanhos e espacamentos daqui.
 *
 * Mudou uma cor ou um tamanho? Mude aqui e rode ferramentas/gerar-css.py.
 * Ver README-css.md.
 */
tailwind.config = {
    darkMode: "class",
    theme: {
        extend: {
            /* Paleta do relógio de ponto antigo (assets/ponto-mobile.css):
               fundo azul-marinho quase preto, azul #3b82f6 e ciano #00aeef.
               Os nomes dos tokens continuam os do design, então a
               marcação das telas não muda — só a cor por trás deles. */
            "colors": {
                "background": "#050810",
                "on-background": "#ffffff",
                "surface": "#0a1322",
                "surface-dim": "#050810",
                "surface-bright": "#101b2f",
                "surface-container-lowest": "#111827",
                "surface-container-low": "#131d31",
                "surface-container": "#172033",
                "surface-container-high": "#1c2740",
                "surface-container-highest": "#22304d",
                "surface-variant": "#22304d",
                "on-surface": "#ffffff",
                "on-surface-variant": "#cbd5e1",
                "surface-tint": "#3b82f6",
                "inverse-surface": "#e2e8f0",
                "inverse-on-surface": "#0a1322",
                "outline": "#64748b",
                "outline-variant": "#334155",
                "primary": "#3b82f6",
                "on-primary": "#ffffff",
                "primary-container": "#60a5fa",
                "on-primary-container": "#eff6ff",
                "primary-fixed": "#1e3a8a",
                "primary-fixed-dim": "#60a5fa",
                "on-primary-fixed": "#eff6ff",
                "on-primary-fixed-variant": "#93c5fd",
                "inverse-primary": "#93c5fd",
                "secondary": "#94a3b8",
                "on-secondary": "#050810",
                "secondary-container": "#1c2740",
                "on-secondary-container": "#cbd5e1",
                "secondary-fixed": "#1c2740",
                "secondary-fixed-dim": "#94a3b8",
                "on-secondary-fixed": "#ffffff",
                "on-secondary-fixed-variant": "#cbd5e1",
                "tertiary": "#00aeef",
                "on-tertiary": "#050810",
                "tertiary-container": "#0e7490",
                "on-tertiary-container": "#cffafe",
                "tertiary-fixed": "#0e7490",
                "tertiary-fixed-dim": "#67e8f9",
                "on-tertiary-fixed": "#cffafe",
                "on-tertiary-fixed-variant": "#a5f3fc",
                "error": "#ef4444",
                "on-error": "#ffffff",
                "error-container": "#7f1d1d",
                "on-error-container": "#fecaca"
            },
            "borderRadius": {
                "DEFAULT": "0.25rem",
                "lg": "0.5rem",
                "xl": "0.75rem",
                "full": "9999px",
                "punch": "24px"
            },
            /* Tudo foi reduzido ~20% em relação ao design: na tela do
               celular os elementos estavam grandes demais. Mexer aqui
               muda o app inteiro de uma vez. */
            "spacing": {
                "touch-target-min": "44px",
                "stack-gap-lg": "18px",
                "container-margin": "16px",
                "stack-gap-sm": "6px",
                "stack-gap-md": "12px",
                "stack-lg": "18px",
                "stack-md": "12px",
                "stack-sm": "6px",
                "punch-btn": "96px"
            },
            "fontFamily": {
                "body-lg": ["Inter"],
                "headline-lg": ["Inter"],
                "body-md": ["Inter"],
                "headline-md": ["Inter"],
                "label-sm": ["Inter"],
                "label-bold": ["Inter"],
                "display-time": ["Inter"]
            },
            "fontSize": {
                "body-lg": ["14px", { "lineHeight": "20px", "fontWeight": "400" }],
                "headline-lg": ["19px", { "lineHeight": "25px", "fontWeight": "700" }],
                "body-md": ["12px", { "lineHeight": "17px", "fontWeight": "400" }],
                "headline-md": ["16px", { "lineHeight": "22px", "fontWeight": "600" }],
                "label-sm": ["10px", { "lineHeight": "13px", "fontWeight": "500" }],
                "label-bold": ["11px", { "lineHeight": "14px", "letterSpacing": "0.04em", "fontWeight": "600" }],
                "display-time": ["38px", { "lineHeight": "44px", "letterSpacing": "-0.02em", "fontWeight": "700" }]
            }
        }
    }
}
