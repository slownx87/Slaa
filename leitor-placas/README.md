# Leitor de placas: Raspberry Pi 3B + câmera Intelbras

Projeto educativo. O Pi lê o vídeo da câmera por RTSP, detecta as placas, lê os
caracteres e grava cada passagem num banco SQLite com a imagem de evidência.

O Pi 3B não consegue reconhecer placa em todos os quadros de um vídeo 1080p. O
projeto contorna isso separando duas coisas: **capturar** é tempo real e nunca
espera; **reconhecer** é lento e roda atrás, numa fila.

```
Câmera Intelbras ──RTSP/TCP, H.264──▶ ffmpeg: decodifica e recorta a zona de leitura
                                        │ quadros crus por pipe
                                        ▼
                          CAPTURA (tempo real, leve)
                          houve movimento? ─▶ evento = pré-gatilho + passagem
                                        ▼
                          FILA em RAM (rareia quadros, não descarta eventos)
                                        ▼
                          RECONHECIMENTO (3 núcleos, prioridade baixa)
                          detector de placa ─▶ OCR ─▶ voto entre quadros
                                        ▼
                          SQLite + imagens:  lida | incerta | sem_placa
```

## O que "não deixar nenhuma placa passar" significa aqui

Nenhum leitor acerta 100%. O que este projeto garante é que nada some em silêncio:

1. Todo movimento na zona vira um evento, com os quadros de 0,5 s antes do gatilho.
2. Todo evento termina com um registro e uma imagem: `lida` (dois ou mais quadros
   concordam, em formato de placa válido), `incerta` (placa vista, leitura fraca:
   fica para revisão) ou `sem_placa` (movimento sem placa: miniatura para auditoria).
3. Se a fila encher, ela descarta quadros de forma uniforme dentro de cada evento,
   mantendo todas as passagens representadas. Só perde um evento se todos já
   estiverem no mínimo, e aí grava `PERDA` no log e soma no contador `perdidos`.

Com isso dá para medir a taxa real de acerto: compare os registros com o que
passou de fato e olhe as imagens `incerta` e `sem_placa`.

## Decisões de desempenho

| Problema no Pi 3B | Decisão |
|---|---|
| Decodificar 1080p por software ocupa quase toda a CPU | `ffmpeg` com o decodificador H.264 do chip de vídeo (`h264_v4l2m2m`), com queda automática para software |
| Converter e copiar o quadro inteiro é caro | O `ffmpeg` recorta a zona antes de qualquer conversão e entrega I420; o plano Y já serve de imagem em cinza para o detector de movimento |
| Rodar a rede neural em todo quadro é impossível | Só quadros de eventos com movimento são examinados: primeiro um a cada 0,25 s (cobertura), depois os vizinhos de onde apareceu placa sem consenso (refino) |
| O reconhecimento pode roubar CPU da captura | 3 threads de inferência com prioridade baixa; o 4º núcleo fica para a captura |
| Um quadro isolado erra caracteres | Voto por posição entre os quadros da passagem, ponderado pela confiança, e correção pelas regras da placa (`AAA9999` e `AAA9A99`) |
| Pacote perdido em UDP corrompe a imagem | RTSP sobre TCP, reconexão automática com espera crescente |

## A câmera decide mais que o software

Placa borrada ou pequena não se recupera depois. Na interface web da câmera
(os nomes dos menus variam conforme o modelo e o firmware):

| Ajuste | Valor | Motivo |
|---|---|---|
| Codec | **H.264** puro (sem H.265, sem H.264+/Smart Codec) | O Pi 3B só decodifica H.264 por hardware |
| Resolução | 1920×1080 | Limite do decodificador do Pi 3B |
| Taxa de quadros | 15 fps | Mais que isso só gasta CPU |
| Taxa de bits | constante (CBR), 4096 kbps ou mais | Pouca taxa de bits borra justamente o que se move |
| Intervalo de quadro I | 15 (igual ao fps) | Recupera a imagem em 1 s depois de uma reconexão |
| Obturador | manual, **1/500 s** ou mais rápido (1/1000 s acima de ~40 km/h) | Borrão de movimento é a maior causa de placa ilegível |
| WDR e redução de ruído 3D | desligado ou no mínimo | Deixam rastro em objetos em movimento |
| Noite | IR ligado, ganho limitado, HLC ligado | A placa é refletiva: com IR ela aparece mesmo com obturador rápido; o HLC segura o farol |

Posição: a placa deve ter **100 px ou mais de largura** na imagem (placa = 40 cm;
largura em px = 0,40 × largura do quadro em px ÷ largura da cena em metros).
Ângulo horizontal e vertical abaixo de 30°. Para o veículo aparecer em pelo
menos 5 quadros: fps × comprimento da zona (m) ÷ velocidade (m/s) ≥ 5.

Endereço RTSP das câmeras e gravadores Intelbras:
`rtsp://USUARIO:SENHA@IP:554/cam/realmonitor?channel=1&subtype=0`
(`subtype=0` é o fluxo principal; caracteres especiais na senha vão codificados, `@` vira `%40`).

Se a câmera for da linha LPR da Intelbras, ela mesma lê a placa e o Pi só
precisaria receber os eventos. É a opção mais confiável, e não é o caso tratado aqui.

## Instalação no Pi

Raspberry Pi OS **64 bits** (Bookworm ou mais novo), rede por **cabo**, fonte de
5 V / 2,5 A e dissipador.

```bash
sudo apt install -y ffmpeg python3-venv
cd leitor-placas
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp config.exemplo.yaml config.yaml && chmod 600 config.yaml   # contém a senha da câmera
```

Depois, nesta ordem:

```bash
.venv/bin/python -m leitor_placas foto          # salva zona.jpg com grade de coordenadas
#   -> ajuste a seção `zona` do config.yaml para o trecho por onde as placas passam
.venv/bin/python -m leitor_placas diagnostico   # mede decodificação e reconhecimento NESTE Pi
.venv/bin/python -m leitor_placas rodar         # roda (Ctrl+C para parar)
.venv/bin/python -m leitor_placas listar        # últimas passagens registradas
```

Na primeira execução os modelos são baixados da internet para `~/.cache`.
Para iniciar junto com o Pi, ajuste os caminhos em `leitor-placas.service` e:

```bash
sudo cp leitor-placas.service /etc/systemd/system/
sudo systemctl enable --now leitor-placas
journalctl -u leitor-placas -f
```

A cada minuto o log mostra `quadros`, `eventos`, `fila`, `raleados`, `perdidos` e
`ms/quadro`. Fila que só cresce quer dizer mais tráfego do que o Pi processa:
diminua a zona, troque o detector para `yolo-v9-t-256-license-plate-end2end` ou
use um Pi 4/5.

Também funciona com um vídeo gravado no lugar da câmera (`camera.url: video.mp4`),
útil para repetir o mesmo trecho enquanto ajusta os parâmetros.

## O que foi testado e o que não foi

Testado (com `pytest`, 24 testes): regras e votação de placa, fila, segmentação
de eventos, relógio dos quadros, e o caminho inteiro com o `ffmpeg` de verdade
lendo um vídeo sintético, inclusive com reconhecimento lento e pouca memória.
Reconexão conferida à mão contra uma fonte de rede que cai e volta.

**Não testado**: nada rodou num Raspberry Pi nem contra uma câmera Intelbras, e os
modelos de detecção e OCR não foram executados (os testes usam um reconhecedor
falso). O código que chama os modelos segue a API das versões fixadas em
`requirements.txt`, lida no código-fonte delas. Pontos a conferir primeiro no Pi:

- `python -c "import onnxruntime"`: há relatos de `Illegal instruction` com
  algumas versões do ONNX Runtime em Raspberry Pi. Se acontecer, instale outra versão.
- O decodificador por hardware: há relatos mistos no Pi 3. O `diagnostico` mostra
  se ele entrega os 15 fps; confira também se a imagem do `foto` com
  `decodificador: hardware` não sai verde. Se falhar, use `software` com a câmera em 720p e 10 fps.
- A velocidade real da detecção (o `diagnostico` mede) e o acerto do OCR com placas
  brasileiras, em especial as de moto, que têm duas linhas.

## Testes

```bash
.venv/bin/pip install pytest
.venv/bin/python -m pytest tests
```

## Privacidade

Placa de veículo ligada a hora e lugar é dado pessoal (LGPD). Aponte a câmera
para a sua própria entrada, não publique os registros e mantenha a retenção
curta (`saida.retencao_dias` apaga registros e imagens antigos sozinho).
