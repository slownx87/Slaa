/*
 * Gerador do espelho de ponto em PDF, direto no navegador.
 *
 * O desenho é o mesmo do relatório antigo (jsPDF + autoTable), com a mesma
 * regra de encolher a fonte até tudo caber em uma página por funcionário.
 *
 * Uso:
 *   EspelhoPDF.gerar({ espelhos, periodo, periodoArquivo, emissao,
 *                      emissaoCompleta, botao, modo });
 *
 *   modo 'unico' (padrão) — UM arquivo PDF com todos, uma página por pessoa.
 *   modo 'zip'            — um PDF por pessoa, todos na raiz do ZIP.
 *
 * Sobre o ZIP: o nome de cada arquivo passa por nomeArquivoSeguro() INTEIRO,
 * período incluído. Antes só o nome da pessoa era limpo e o período entrava
 * cru ("01/08/2026 a 25/08/2026"); o JSZip lê cada "/" como pasta, então o
 * espelho de cada funcionário ficava enterrado em quatro pastas.
 */
window.EspelhoPDF = (function () {
    'use strict';

    var estado = { emissao: '', emissaoCompleta: '' };

    var CDN_JSPDF = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    var CDN_AUTOTABLE = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js';
    var CDN_JSZIP = 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';

    function carregarScript(src){
        return new Promise(function(resolve, reject){
            if (document.querySelector('script[data-cdn="' + src + '"]')) { resolve(); return; }
            var s = document.createElement('script');
            s.src = src;
            s.setAttribute('data-cdn', src);
            s.onload = function(){ resolve(); };
            s.onerror = function(){ reject(new Error('Falha ao carregar biblioteca (precisa de internet).')); };
            document.head.appendChild(s);
        });
    }

    /* Tira tudo que vira pasta ou quebra o sistema de arquivos: / \ : * ? " < > |
     * Também troca ".." e pontos do começo/fim, que alguns descompactadores
     * tratam como caminho. */
    function nomeArquivoSeguro(nome, fallback){
        nome = String(nome || '')
            .replace(/[\\/:*?"<>|]+/g, '-')
            .replace(/\.{2,}/g, '.')
            .replace(/\s+/g, ' ')
            .replace(/^[\s.-]+|[\s.-]+$/g, '')
            .slice(0, 120);
        return nome || fallback;
    }

    /* Nome final de um espelho: "Fulano de Tal - 2026-08-01_a_2026-08-31". */
    function nomeDoEspelho(esp, periodoArquivo, indice){
        var base = nomeArquivoSeguro(esp && esp.nome, 'funcionario-' + (indice + 1));
        if (periodoArquivo) base += ' - ' + periodoArquivo;
        return nomeArquivoSeguro(base, 'espelho-' + (indice + 1));
    }

    function baixarBlob(blob, nomeArquivo){
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = nomeArquivo;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 5000);
    }

    /* Desenha o espelho de UM funcionário na página atual do documento.
     * Devolve quantas páginas ele ocupou. */
    function desenharEspelho(pdf, esp, periodo, fontSize, cellPadding){
        var paginasAntes = pdf.getNumberOfPages();
        var pw = pdf.internal.pageSize.getWidth();
        var ph = pdf.internal.pageSize.getHeight();
        var ml = 10;
        var mr = pw - 10;
        var rodapeAltura = 14; // espaço reservado no rodapé (certificação + assinaturas)

        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(14);
        pdf.setTextColor(40, 50, 77);
        pdf.text('Relatório Espelho de Ponto', ml, 13);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(9);
        pdf.text('Período: ' + periodo, mr, 13, { align: 'right' });

        // Blocos de identificação (empresa à esquerda, funcionário à direita).
        pdf.setFontSize(7.5);
        var yTop = 20;
        var colR = 150;
        function bloco(linhas, x, y){
            for (var k = 0; k < linhas.length; k++){
                var ln = linhas[k];
                pdf.setFont('helvetica', 'bold');
                pdf.text(ln[0], x, y);
                var lblW = pdf.getTextWidth(ln[0]) + 1.5;
                pdf.setFont('helvetica', 'normal');
                pdf.text(String(ln[1] || ''), x + lblW, y);
                y += 3.7;
            }
            return y;
        }
        var yE = bloco([
            ['Empresa:', esp.empresa],
            ['Endereço:', esp.endereco],
            ['CNPJ:', esp.cnpj],
            ['Fone:', esp.fone],
            ['Emissão:', estado.emissao || '']
        ], ml, yTop);
        var yF = bloco([
            ['Funcionário:', esp.nome],
            ['Cargo:', esp.cargo],
            ['Numero CTPS:', esp.ctps],
            ['Admissão:', esp.admissao],
            ['RG:', esp.rg],
            ['PIS:', esp.pis],
            ['CPF:', esp.cpf]
        ].concat(esp.matricula ? [['Matrícula:', esp.matricula]] : []), colR, yTop);

        var startY = Math.max(yE, yF) + 2;

        pdf.autoTable({
            startY: startY,
            head: [['DATA', 'HORÁRIO', 'AUSÊNCIA', 'PONTOS', 'INTERVALOS', 'HORAS TRAB.', 'HORAS PROG.', 'ATRASOS', 'SALDO']],
            body: esp.rows,
            foot: [['TOTAL', '', '', '', '', esp.total_trab, esp.total_prog || '00:00', esp.total_atraso || '00:00', esp.total_saldo]],
            theme: 'grid',
            styles: { fontSize: fontSize, cellPadding: cellPadding, overflow: 'linebreak', textColor: [43, 53, 80], lineWidth: 0.1, valign: 'middle' },
            headStyles: { fillColor: [241, 244, 249], textColor: [43, 53, 80], fontStyle: 'bold' },
            footStyles: { fillColor: [255, 255, 255], textColor: [43, 53, 80], fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [244, 247, 251] },
            columnStyles: {
                0: { cellWidth: 26 },
                // Cargo de escala imprime "06:00-14:00" aqui: 15mm quebrava a
                // coluna em duas linhas e esticava a tabela à toa.
                1: { cellWidth: 21, halign: 'center' },
                2: { cellWidth: 20 },
                3: { cellWidth: 'auto' },
                4: { cellWidth: 'auto' },
                5: { cellWidth: 17, halign: 'center' },
                6: { cellWidth: 17, halign: 'center' },
                7: { cellWidth: 15, halign: 'center' },
                8: { cellWidth: 15, halign: 'center' }
            },
            margin: { left: ml, right: 10, bottom: rodapeAltura + 4 }
        });

        var paginas = pdf.getNumberOfPages() - paginasAntes + 1;
        var finalY = pdf.lastAutoTable ? pdf.lastAutoTable.finalY : startY;
        var yEnd = finalY + 8;

        // Rodapé só é desenhado quando o espelho coube em uma página.
        if (paginas === 1 && yEnd <= ph - 8) {
            // Deixa claro quando algum horário do espelho foi estimado.
            if (esp.tem_estimativa) {
                pdf.setFontSize(6.5);
                pdf.setFont('helvetica', 'italic');
                pdf.setTextColor(150, 60, 60);
                pdf.text('* Saida estimada entre 05:50 e 06:00 - o funcionario nao registrou a saida neste turno noturno.', ml, yEnd - 3.5);
                pdf.setTextColor(43, 53, 80);
            }

            pdf.setFontSize(7.5);
            pdf.setFont('helvetica', 'bold');
            pdf.text('Certificado digitalmente por Vss E Acj', ml, yEnd);
            pdf.setFont('helvetica', 'normal');
            pdf.text('Data: ' + (estado.emissaoCompleta || ''), ml, yEnd + 4);
            pdf.text('___________________________', mr - 70, yEnd);
            pdf.text('Empresa', mr - 58, yEnd + 4);
            pdf.text('___________________________', mr, yEnd, { align: 'right' });
            pdf.text('Funcionário', mr - 12, yEnd + 4);
        }

        return paginas;
    }

    var TENTATIVAS_FONTE = [8, 7.5, 7, 6.5, 6, 5.5, 5, 4.5, 4, 3.5];
    function padding(fs){ return Math.max(0.45, fs * 0.18); }

    /* Maior fonte em que o espelho da pessoa ainda cabe numa página só.
     * Desenha em documentos descartáveis: nada disso vai para a saída. */
    function medirFonte(jsPDF, esp, periodo){
        for (var i = 0; i < TENTATIVAS_FONTE.length; i++){
            var fs = TENTATIVAS_FONTE[i];
            var teste = new jsPDF('l', 'mm', 'a4');
            if (desenharEspelho(teste, esp, periodo, fs, padding(fs)) === 1) return fs;
        }
        return TENTATIVAS_FONTE[TENTATIVAS_FONTE.length - 1]; // caso extremo
    }

    // PDF de um funcionário só.
    function gerarPdfFuncionario(jsPDF, esp, periodo){
        var fs = medirFonte(jsPDF, esp, periodo);
        var pdf = new jsPDF('l', 'mm', 'a4');
        desenharEspelho(pdf, esp, periodo, fs, padding(fs));
        return pdf;
    }

    // UM PDF com todos: cada funcionário começa numa página nova.
    function gerarPdfUnico(jsPDF, espelhos, periodo, aviso){
        var pdf = new jsPDF('l', 'mm', 'a4');
        for (var i = 0; i < espelhos.length; i++){
            if (aviso) aviso('Gerando... (' + (i + 1) + '/' + espelhos.length + ')');
            if (i > 0) pdf.addPage();
            var fs = medirFonte(jsPDF, espelhos[i], periodo);
            desenharEspelho(pdf, espelhos[i], periodo, fs, padding(fs));
        }
        return pdf;
    }

    async function gerar(opcoes) {
        var espelhos = opcoes.espelhos || [];
        var botao = opcoes.botao || null;
        var periodo = opcoes.periodo || '';
        /* Só este entra em nome de arquivo. Se a página não mandar, deriva do
         * texto do período trocando as barras — nunca deixa "/" passar. */
        var periodoArquivo = nomeArquivoSeguro(opcoes.periodoArquivo || periodo, '');
        var modo = opcoes.modo === 'zip' ? 'zip' : 'unico';
        estado.emissao = opcoes.emissao || '';
        estado.emissaoCompleta = opcoes.emissaoCompleta || '';

        if (!espelhos.length) {
            throw new Error('Nenhum funcionário no filtro escolhido.');
        }

        var textoOriginal = botao ? botao.innerHTML : '';
        function aviso(texto) {
            if (botao) botao.innerHTML = texto;
        }

        try {
            if (botao) botao.disabled = true;
            aviso('Carregando...');
            await carregarScript(CDN_JSPDF);
            await carregarScript(CDN_AUTOTABLE);

            var jsPDF = window.jspdf.jsPDF;

            /* Um funcionário só: baixa o PDF direto, sem ZIP nem página extra. */
            if (espelhos.length === 1) {
                aviso('Gerando PDF...');
                var unico = gerarPdfFuncionario(jsPDF, espelhos[0], periodo);
                unico.save(nomeDoEspelho(espelhos[0], periodoArquivo, 0) + '.pdf');
                return 1;
            }

            if (modo === 'unico') {
                var juntos = gerarPdfUnico(jsPDF, espelhos, periodo, aviso);
                aviso('Salvando...');
                juntos.save(nomeArquivoSeguro('espelhos-ponto' + (periodoArquivo ? ' ' + periodoArquivo : ''), 'espelhos-ponto') + '.pdf');
                return espelhos.length;
            }

            await carregarScript(CDN_JSZIP);
            var zip = new JSZip();
            var usados = {};

            for (var i = 0; i < espelhos.length; i++) {
                aviso('Gerando... (' + (i + 1) + '/' + espelhos.length + ')');
                var pdf = gerarPdfFuncionario(jsPDF, espelhos[i], periodo);

                /* Nome já limpo de "/" — os PDFs ficam todos na raiz do ZIP. */
                var base = nomeDoEspelho(espelhos[i], periodoArquivo, i);
                var arquivo = base + '.pdf';
                var n = 2;
                while (usados[arquivo]) { arquivo = base + ' (' + (n++) + ').pdf'; }
                usados[arquivo] = true;
                zip.file(arquivo, pdf.output('blob'));
            }

            aviso('Compactando...');
            var blob = await zip.generateAsync({ type: 'blob' });
            baixarBlob(blob, nomeArquivoSeguro('espelhos-ponto' + (periodoArquivo ? ' ' + periodoArquivo : ''), 'espelhos-ponto') + '.zip');
            return espelhos.length;
        } finally {
            if (botao) { botao.disabled = false; botao.innerHTML = textoOriginal; }
        }
    }

    return { gerar: gerar, nomeArquivoSeguro: nomeArquivoSeguro };
})();
