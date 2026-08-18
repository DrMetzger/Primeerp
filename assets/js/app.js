/**
 * Prime Hub -- substitui o comportamento do bootstrap.bundle.min.js (modal,
 * abas, colapsar navbar, fechar alerta) com JavaScript puro, sem depender
 * de nenhuma biblioteca externa. Mantém os MESMOS atributos data-bs-* que
 * já existiam no HTML (data-bs-toggle="modal"/"tab"/"collapse",
 * data-bs-target, data-bs-dismiss) e dispara os MESMOS nomes de evento
 * (show.bs.modal, shown.bs.tab) que o código de cada página já escuta --
 * assim nenhuma outra página precisou ser alterada além da troca do link do
 * bootstrap pelo deste arquivo.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------ Alertas
    document.addEventListener('click', function (e) {
        var fecharAlerta = e.target.closest('[data-bs-dismiss="alert"]');
        if (fecharAlerta) {
            var alertaEl = fecharAlerta.closest('.alert');
            if (alertaEl) alertaEl.remove();
        }
    });

    // -------------------------------------------------- Navbar (mobile)
    document.addEventListener('click', function (e) {
        var toggler = e.target.closest('[data-bs-toggle="collapse"]');
        if (toggler) {
            var alvoSel = toggler.getAttribute('data-bs-target');
            var alvo = alvoSel ? document.querySelector(alvoSel) : null;
            if (alvo) alvo.classList.toggle('show');
        }
    });

    // ------------------------------------------------------------- Abas
    function mostrarAba(link) {
        if (!link) return;
        var href = link.getAttribute('href');
        var painel = href ? document.querySelector(href) : null;
        if (!painel) return;

        var listaNav = link.closest('.nav-tabs') || document;
        listaNav.querySelectorAll('.nav-link').forEach(function (l) { l.classList.remove('active'); });
        link.classList.add('active');

        // :scope > .tab-pane (não .tab-pane sem escopo) -- senão isso também
        // desativa qualquer sub-aba ANINHADA dentro do painel (ex.: as abas
        // Configurações/Calendário/... de cada setor, na aba Setores),
        // deixando a sub-aba em branco até clicar nela de novo manualmente.
        var conteudo = painel.closest('.tab-content') || document;
        conteudo.querySelectorAll(':scope > .tab-pane').forEach(function (p) { p.classList.remove('show', 'active'); });
        painel.classList.add('show', 'active');

        link.dispatchEvent(new CustomEvent('shown.bs.tab', { bubbles: true, detail: { target: href } }));
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('[data-bs-toggle="tab"]');
        if (link) {
            e.preventDefault();
            mostrarAba(link);
        }
    });

    // ------------------------------------------------------------ Modais
    var backdropAtual = null;

    function criarBackdrop() {
        if (backdropAtual) return backdropAtual;
        backdropAtual = document.createElement('div');
        backdropAtual.className = 'modal-backdrop fade';
        document.body.appendChild(backdropAtual);
        requestAnimationFrame(function () { backdropAtual.classList.add('show'); });
        return backdropAtual;
    }

    function removerBackdrop() {
        if (backdropAtual) {
            backdropAtual.remove();
            backdropAtual = null;
        }
    }

    function abrirModal(modalEl, gatilho) {
        if (!modalEl) return;
        // relatedTarget existe de graça em MouseEvent nativo, mas NÃO em
        // CustomEvent -- sem atribuir isso à mão, todo código que abre um
        // modal via data-bs-toggle/data-bs-target e lê event.relatedTarget
        // pra saber QUAL card/botão foi clicado (ex.: o player de vídeo
        // aula, os cards de ambiente T-Cloud) recebia sempre undefined e
        // ficava com o modal vazio/travado, sem nenhum erro no console pra
        // dar pista do motivo. Bug real reportado pelo Kleber (vídeo aula
        // "Assistir" abrindo em branco, travado em 0:00).
        var eventoShow = new CustomEvent('show.bs.modal', { bubbles: true });
        eventoShow.relatedTarget = gatilho || null;
        modalEl.dispatchEvent(eventoShow);
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
        criarBackdrop();
        var eventoShown = new CustomEvent('shown.bs.modal', { bubbles: true });
        eventoShown.relatedTarget = gatilho || null;
        modalEl.dispatchEvent(eventoShown);
    }

    function fecharModal(modalEl) {
        if (!modalEl) return;
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        document.body.classList.remove('modal-open');
        removerBackdrop();
        modalEl.dispatchEvent(new CustomEvent('hidden.bs.modal', { bubbles: true }));
    }

    document.addEventListener('click', function (e) {
        var abridor = e.target.closest('[data-bs-toggle="modal"]');
        if (abridor) {
            var sel = abridor.getAttribute('data-bs-target');
            abrirModal(sel ? document.querySelector(sel) : null, abridor);
            return;
        }

        var fechador = e.target.closest('[data-bs-dismiss="modal"]');
        if (fechador) {
            fecharModal(fechador.closest('.modal'));
            return;
        }

        // Clique fora do modal-content (no fundo escurecido) fecha também.
        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            fecharModal(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var aberto = document.querySelector('.modal.show');
            if (aberto) fecharModal(aberto);
        }
    });

    // ------------------------------------------------------ Máscara de telefone
    // Formata (XX) XXXXX-XXXX (celular) ou (XX) XXXX-XXXX (fixo) enquanto a
    // pessoa digita. Delegado no document (funciona também em campos
    // preenchidos depois via JS, ex. o modal de Clientes).
    function formatarTelefone(valor) {
        var digitos = (valor || '').replace(/\D/g, '').slice(0, 11);
        if (digitos.length > 10) {
            return digitos.replace(/(\d{2})(\d{5})(\d{0,4})/, '($1) $2-$3').replace(/-$/, '');
        }
        if (digitos.length > 5) {
            return digitos.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3').replace(/-$/, '');
        }
        if (digitos.length > 2) {
            return digitos.replace(/(\d{2})(\d{0,5})/, '($1) $2');
        }
        if (digitos.length > 0) {
            return '(' + digitos;
        }
        return '';
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('input-telefone')) {
            e.target.value = formatarTelefone(e.target.value);
        }
    });

    // --------------------------------------------------------- Máscara de CNPJ
    // Formata XX.XXX.XXX/XXXX-XX enquanto a pessoa digita (mesma ideia da
    // máscara de telefone acima).
    function formatarCnpj(valor) {
        var digitos = (valor || '').replace(/\D/g, '').slice(0, 14);
        if (digitos.length > 12) {
            return digitos.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})/, '$1.$2.$3/$4-$5').replace(/-$/, '');
        }
        if (digitos.length > 8) {
            return digitos.replace(/(\d{2})(\d{3})(\d{3})(\d{0,4})/, '$1.$2.$3/$4').replace(/\/$/, '');
        }
        if (digitos.length > 5) {
            return digitos.replace(/(\d{2})(\d{3})(\d{0,3})/, '$1.$2.$3').replace(/\.$/, '');
        }
        if (digitos.length > 2) {
            return digitos.replace(/(\d{2})(\d{0,3})/, '$1.$2').replace(/\.$/, '');
        }
        return digitos;
    }

    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('input-cnpj')) {
            e.target.value = formatarCnpj(e.target.value);
        }
    });

    // ----------------------------------------------- Cor no select de prioridade
    // opcoes_prioridade_html() (helpers.php) já colore cada <option> -- mas
    // isso só aparece com a lista ABERTA. A caixa fechada (valor
    // selecionado) não herda a cor da option em nenhum navegador, ficando
    // sempre em preto/cinza padrão. Copia a cor da option escolhida pro
    // próprio <select> sempre que o valor mudar (e uma vez ao carregar a
    // página, pro valor padrão/pré-preenchido já vir colorido).
    function colorirSelectPrioridade(select) {
        var opcao = select.options[select.selectedIndex];
        if (!opcao) return;
        select.style.color = opcao.style.color || '';
        select.style.fontWeight = opcao.style.color ? '600' : '';
    }

    document.querySelectorAll('select[name="prioridade"]').forEach(colorirSelectPrioridade);

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('select[name="prioridade"]')) {
            colorirSelectPrioridade(e.target);
        }
    });

    // Modal aberto depois do carregamento inicial (ex.: "Registrar chamado")
    // ainda não tinha o select no DOM na hora do querySelectorAll acima --
    // aplica de novo quando qualquer modal é mostrado.
    document.addEventListener('show.bs.modal', function (e) {
        if (e.target.querySelectorAll) {
            e.target.querySelectorAll('select[name="prioridade"]').forEach(colorirSelectPrioridade);
        }
    });

    // ------------------------------------------------------- Lightbox de anexo
    // Clicar numa miniatura de imagem (chat de chamado -- ver
    // render_anexo_item_html() em helpers.php) abre ela em tela grande sem
    // baixar nada, em vez de forçar download só pra ver o que é (pedido do
    // Kleber: "abrir imagem por imagem é foda"). Criado uma única vez e
    // reaproveitado -- não precisa alterar nenhum template de página.
    var lightboxEl = null;
    function garantirLightbox() {
        if (lightboxEl) return lightboxEl;
        lightboxEl = document.createElement('div');
        lightboxEl.className = 'lightbox-anexo';
        lightboxEl.innerHTML = '<button type="button" class="lightbox-anexo-fechar" aria-label="Fechar">&times;</button>'
            + '<img alt="">'
            + '<div class="lightbox-anexo-legenda"></div>';
        document.body.appendChild(lightboxEl);
        return lightboxEl;
    }

    function fecharLightbox() {
        if (lightboxEl) lightboxEl.classList.remove('show');
    }

    document.addEventListener('click', function (e) {
        var abrir = e.target.closest('.botao-abrir-lightbox');
        if (abrir) {
            e.preventDefault();
            var el = garantirLightbox();
            el.querySelector('img').src = abrir.getAttribute('data-lightbox-src') || '';
            el.querySelector('.lightbox-anexo-legenda').textContent = abrir.getAttribute('data-lightbox-legenda') || '';
            el.classList.add('show');
            return;
        }

        if (lightboxEl && (e.target === lightboxEl || e.target.closest('.lightbox-anexo-fechar'))) {
            fecharLightbox();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') fecharLightbox();
    });

    // --------------------------------------------------- Anexos (staging)
    // Troca o <input type="file"> nativo (rótulo do próprio navegador --
    // "Escolher Ficheiros" em português de Portugal, print do Kleber) por um
    // botão próprio ("Escolher anexos") + lista de pendentes abaixo. Fluxo
    // pedido: escolher (pode repetir em vários lotes) -> "Enviar anexos" só
    // ADICIONA à lista, sem mandar o chamado -> escreve a mensagem por
    // último -> "Enviar resposta" manda tudo junto de uma vez. Também
    // valida quantidade/tamanho aqui, ANTES de qualquer envio -- sem essa
    // trava, um arquivo grande demais só é rejeitado depois de subir tudo,
    // caindo numa tela de erro genérica do nginx (413), feia pro cliente ver.
    var ICONES_ANEXO_POR_EXTENSAO = {
        pdf: 'bi-filetype-pdf', doc: 'bi-filetype-doc', docx: 'bi-filetype-docx',
        xls: 'bi-filetype-xls', xlsx: 'bi-filetype-xlsx', ppt: 'bi-filetype-ppt', pptx: 'bi-filetype-pptx',
        txt: 'bi-filetype-txt', csv: 'bi-filetype-csv', rtf: 'bi-file-earmark-richtext',
        zip: 'bi-file-earmark-zip', rar: 'bi-file-earmark-zip', '7z': 'bi-file-earmark-zip',
        odt: 'bi-file-earmark-text', ods: 'bi-file-earmark-spreadsheet', odp: 'bi-file-earmark-slides',
        jpg: 'bi-filetype-jpg', jpeg: 'bi-filetype-jpg', png: 'bi-filetype-png', gif: 'bi-filetype-gif',
        webp: 'bi-file-earmark-image'
    };

    function iconeAnexoPorNome(nome) {
        var ext = (nome.split('.').pop() || '').toLowerCase();
        return ICONES_ANEXO_POR_EXTENSAO[ext] || 'bi-file-earmark';
    }

    function formatarTamanhoArquivo(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    }

    function inicializarAnexoStager(widget) {
        var input = widget.querySelector('.anexos-stager-input');
        var btnEnviar = widget.querySelector('.anexos-stager-enviar');
        var spanPendente = widget.querySelector('.anexos-stager-pendente');
        var elErro = widget.querySelector('.anexos-stager-erro');
        var lista = widget.querySelector('.anexos-stager-lista');
        var maxArquivos = parseInt(widget.getAttribute('data-max-arquivos'), 10) || 5;
        var maxTamanho = parseInt(widget.getAttribute('data-max-tamanho-bytes'), 10) || (500 * 1024 * 1024);

        // Dica de arrastar-e-soltar injetada aqui (em vez de em cada
        // template) pra não precisar repetir/manter em sincronia em todo
        // lugar que usa esse widget (resposta de chamado, abrir chamado etc.).
        var controles = widget.querySelector('.anexos-stager-controles');
        if (controles && !controles.querySelector('.anexos-stager-dica')) {
            var dica = document.createElement('span');
            dica.className = 'anexos-stager-dica text-muted small';
            dica.textContent = 'ou arraste o arquivo aqui';
            controles.appendChild(dica);
        }

        var arquivosNaLista = [];
        var pendentes = [];

        function mostrarErro(msg) {
            elErro.textContent = msg;
            elErro.classList.remove('d-none');
        }

        function limparErro() {
            elErro.classList.add('d-none');
            elErro.textContent = '';
        }

        function limparPendentes() {
            pendentes = [];
            btnEnviar.disabled = true;
            spanPendente.textContent = '';
        }

        function sincronizarInput() {
            var dt = new DataTransfer();
            arquivosNaLista.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        }

        function renderizarLista() {
            lista.innerHTML = '';
            arquivosNaLista.forEach(function (arquivo, indice) {
                var li = document.createElement('li');
                li.className = 'anexos-stager-item';
                li.innerHTML = '<i class="bi ' + iconeAnexoPorNome(arquivo.name) + '"></i>'
                    + '<span class="anexos-stager-item-nome" title="' + arquivo.name.replace(/"/g, '&quot;') + '">' + arquivo.name + '</span>'
                    + '<span class="anexos-stager-item-tamanho">' + formatarTamanhoArquivo(arquivo.size) + '</span>'
                    + '<button type="button" class="anexos-stager-item-remover" aria-label="Remover" data-indice="' + indice + '">&times;</button>';
                lista.appendChild(li);
            });
        }

        // Valida e efetiva a entrada de um lote de arquivos na lista de
        // verdade -- usado tanto pelo botão "Enviar anexos" (arquivos
        // escolhidos pelo seletor nativo) quanto por colar uma imagem da
        // área de transferência (ver listener de "paste" abaixo), que não
        // passa pelo <input type="file"> nenhum.
        function tentarAdicionar(novosArquivos) {
            var combinados = arquivosNaLista.concat(novosArquivos);
            if (combinados.length > maxArquivos) {
                mostrarErro('Envie no máximo ' + maxArquivos + ' arquivos por vez.');
                return;
            }

            var grandeDemais = combinados.find(function (f) { return f.size > maxTamanho; });
            if (grandeDemais) {
                mostrarErro('"' + grandeDemais.name + '" passa do limite de ' + Math.round(maxTamanho / 1024 / 1024) + 'MB por arquivo.');
                return;
            }

            limparErro();
            arquivosNaLista = combinados;
            renderizarLista();
            sincronizarInput();
        }

        widget.addEventListener('click', function (e) {
            if (e.target.closest('.anexos-stager-escolher')) {
                input.click();
                return;
            }

            var remover = e.target.closest('.anexos-stager-item-remover');
            if (remover) {
                arquivosNaLista.splice(parseInt(remover.getAttribute('data-indice'), 10), 1);
                renderizarLista();
                sincronizarInput();
                return;
            }

            if (e.target.closest('.anexos-stager-enviar')) {
                if (!pendentes.length) return;
                tentarAdicionar(pendentes);
                limparPendentes();
            }
        });

        input.addEventListener('change', function () {
            limparErro();
            pendentes = Array.from(input.files || []);
            // Sai do input assim que capturado -- só entra na lista de fato
            // ao clicar "Enviar anexos" (senão um "Enviar resposta" direto,
            // sem passar por ali, mandaria o arquivo sem validar).
            input.value = '';
            btnEnviar.disabled = pendentes.length === 0;
            spanPendente.textContent = pendentes.length
                ? pendentes.length + ' arquivo(s) selecionado(s) -- clique em "Enviar anexos"'
                : '';
        });

        // Colar imagem da área de transferência (Ctrl+V/Cmd+V) direto na
        // resposta do chamado -- pedido do Kleber: print de tela é o anexo
        // mais comum num chamado de suporte, e ficar salvando arquivo pra
        // depois escolher no seletor toda vez era o passo mais chato do
        // fluxo. Adiciona direto na lista (sem passar pelo botão "Enviar
        // anexos", que só existe pro fluxo de escolher arquivo no disco).
        // Ouve no FORM inteiro (não só no widget) porque o cursor costuma
        // estar no campo de mensagem, não dentro do widget de anexo, na
        // hora de colar.
        var formDoWidget = widget.closest('form');
        if (formDoWidget) {
            formDoWidget.addEventListener('paste', function (e) {
                var itens = (e.clipboardData || window.clipboardData || {}).items;
                if (!itens) return;

                var imagens = [];
                for (var i = 0; i < itens.length; i++) {
                    if (itens[i].kind === 'file' && itens[i].type.indexOf('image/') === 0) {
                        var arquivo = itens[i].getAsFile();
                        if (arquivo) imagens.push(arquivo);
                    }
                }
                if (!imagens.length) return;

                // Cola de verdade num campo de texto normal continua
                // funcionando (não existe imagem pra colar como texto) --
                // só evita o comportamento padrão do navegador de tentar
                // colar a imagem como conteúdo do campo (alguns navegadores
                // fazem isso em campo contenteditable, não em <textarea>,
                // mas não custa garantir).
                e.preventDefault();

                // Nome genérico do clipboard (ex. "image.png") não diz nada
                // -- carimba com data/hora pra dar pra diferenciar duas
                // capturas de tela coladas na mesma resposta.
                imagens = imagens.map(function (img, indice) {
                    var extensao = (img.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                    var nomeArquivo = 'print-colado-' + Date.now() + (indice ? '-' + indice : '') + '.' + extensao;
                    return new File([img], nomeArquivo, { type: img.type });
                });

                tentarAdicionar(imagens);
            });

            // Arrastar e soltar arquivo(s) direto na área do chamado --
            // pedido do Kleber, mesmo espírito de colar print (Ctrl+V):
            // menos passos que abrir o seletor de arquivo. Ouve no FORM
            // inteiro (não só no widget pequeno de anexo), pra soltar em
            // qualquer lugar da área da resposta/chamado já funcionar, sem
            // precisar acertar o alvo pequeno do "Escolher anexos".
            var contadorArrastar = 0;
            formDoWidget.addEventListener('dragenter', function (e) {
                e.preventDefault();
                contadorArrastar++;
                formDoWidget.classList.add('anexos-arrastando');
            });
            formDoWidget.addEventListener('dragover', function (e) {
                // Sem isso o navegador tenta ABRIR o arquivo numa aba nova
                // em vez de disparar o evento "drop".
                e.preventDefault();
            });
            formDoWidget.addEventListener('dragleave', function (e) {
                e.preventDefault();
                contadorArrastar = Math.max(0, contadorArrastar - 1);
                // dragenter/dragleave disparam pra cada elemento filho que o
                // mouse passa por cima -- só tira o destaque quando realmente
                // saiu da área toda (contador voltou a zero), senão a borda
                // fica "piscando" ao arrastar por cima de um texto/botão
                // dentro do form.
                if (contadorArrastar === 0) formDoWidget.classList.remove('anexos-arrastando');
            });
            formDoWidget.addEventListener('drop', function (e) {
                e.preventDefault();
                contadorArrastar = 0;
                formDoWidget.classList.remove('anexos-arrastando');

                var arquivos = Array.from((e.dataTransfer || {}).files || []);
                if (arquivos.length) tentarAdicionar(arquivos);
            });
        }
    }

    document.querySelectorAll('.anexos-stager').forEach(inicializarAnexoStager);

    // -------------------------------------------- Barra de progresso de upload
    // Um <input type="file"> num <form> normal (POST full-page) não dá
    // NENHUM aviso de progresso pro navegador mostrar -- pedido do Kleber
    // depois de levantar o limite de anexo/arquivo pra 500MB: sem isso a
    // pessoa não tem como saber se um arquivo grande travou ou só está
    // subindo. Intercepta TODO submit de formulário que tenha um arquivo de
    // verdade selecionado (funciona pro anexos-stager E pra qualquer outro
    // <input type="file"> "solto" da plataforma -- foto de perfil, logo,
    // documento etc. -- sem precisar tocar em cada template) e reenvia via
    // XMLHttpRequest, que expõe xhr.upload.onprogress. Mantém EXATAMENTE o
    // mesmo método/campos/action do form original (FormData(form) captura
    // tudo, inclusive campos ocultos como csrf_token); só troca COMO o
    // navegador manda. Ao terminar, navega pra URL final da resposta (que já
    // segue qualquer redirect do padrão POST-redirect-GET do backend) --
    // efeito idêntico a um submit nativo, só que com barra de progresso.
    var overlayUploadEl = null;
    function garantirOverlayUpload() {
        if (overlayUploadEl) return overlayUploadEl;
        overlayUploadEl = document.createElement('div');
        overlayUploadEl.className = 'overlay-upload-progresso';
        overlayUploadEl.innerHTML = '<div class="overlay-upload-progresso-caixa">'
            + '<div class="overlay-upload-progresso-titulo">Enviando arquivo...</div>'
            + '<div class="progress"><div class="progress-bar" role="progressbar">0%</div></div>'
            + '<div class="overlay-upload-progresso-detalhe"></div>'
            + '</div>';
        document.body.appendChild(overlayUploadEl);
        return overlayUploadEl;
    }

    function temArquivoDeVerdadeSelecionado(form) {
        var inputs = form.querySelectorAll('input[type="file"]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].files && inputs[i].files.length) return true;
        }
        return false;
    }

    document.addEventListener('submit', function (e) {
        // Formulário próprio já cancelou o submit por conta dele (ex.:
        // confirm() recusado, ou um handler específico como o de "gerar
        // sugestão de IA" que faz seu próprio fetch) -- não faz sentido
        // hijackar de novo por cima.
        if (e.defaultPrevented) return;

        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!temArquivoDeVerdadeSelecionado(form)) return;

        e.preventDefault();

        var overlay = garantirOverlayUpload();
        var barra = overlay.querySelector('.progress-bar');
        var detalhe = overlay.querySelector('.overlay-upload-progresso-detalhe');
        barra.style.width = '0%';
        barra.textContent = '0%';
        detalhe.textContent = '';
        overlay.classList.add('show');

        // Enquanto esse overlay estiver na tela, tentar sair/recarregar a
        // página mostra o aviso nativo do navegador -- sem isso, alguém que
        // acha que travou (ver fase "Registrando..." abaixo) sai da página
        // sem querer no meio do envio de um arquivo grande e ele nem termina
        // de salvar no servidor. Pedido explícito do Kleber depois de quase
        // sair da página com uma vídeo aula grande travada em "Registrando".
        var avisarAntesDeSair = function (ev) { ev.preventDefault(); ev.returnValue = ''; };
        window.addEventListener('beforeunload', avisarAntesDeSair);
        var pararDeAvisarAntesDeSair = function () { window.removeEventListener('beforeunload', avisarAntesDeSair); };

        var dados = new FormData(form);
        var tituloOverlay = overlay.querySelector('.overlay-upload-progresso-titulo');
        var tamanhoTotalUpload = 0;
        var xhr = new XMLHttpRequest();
        xhr.open((form.getAttribute('method') || 'POST').toUpperCase(), form.getAttribute('action') || window.location.href);
        // Rede de segurança contra qualquer travamento silencioso no
        // servidor (ex.: um arquivo enorme cujo processamento passa do
        // esperado) -- sem isso o overlay podia ficar "Registrando..." pra
        // sempre, sem nenhum jeito de desistir. 15min cobre com folga até o
        // maior upload previsto (vídeo aula de até 5GB).
        xhr.timeout = 15 * 60 * 1000;
        xhr.upload.addEventListener('progress', function (ev) {
            if (!ev.lengthComputable) return;
            tamanhoTotalUpload = ev.total;
            var pct = Math.round((ev.loaded / ev.total) * 100);
            barra.style.width = pct + '%';
            barra.textContent = pct + '%';
            detalhe.textContent = formatarTamanhoArquivo(ev.loaded) + ' de ' + formatarTamanhoArquivo(ev.total);
        });
        // O arquivo já terminou de subir, mas o servidor ainda está
        // processando (salvando no banco, recarregando a página depois do
        // redirect) -- sem isso a barra ficava travada em "100%" por vários
        // segundos sem nenhum aviso, parecendo que travou de verdade. O
        // listrado animado (ver .progress-bar-processando no style.css)
        // deixa claro que ainda tem coisa rodando mesmo sem um % real pra
        // mostrar -- não dá pra saber o progresso dessa parte (o navegador
        // já mandou tudo). O aviso de "pode levar minutos" só aparece pra
        // arquivo GRANDE de verdade (>15MB) -- pra um anexo pequeno (a
        // maioria) esse aviso só assustava sem motivo, fazendo uma espera de
        // 1-2s (recarregar a página depois) parecer um travamento sério.
        xhr.upload.addEventListener('load', function () {
            if (tituloOverlay) tituloOverlay.textContent = 'Registrando...';
            barra.classList.add('progress-bar-processando');
            detalhe.textContent = tamanhoTotalUpload > 15 * 1024 * 1024
                ? 'Isso pode levar alguns minutos em arquivos grandes. Não saia nem atualize essa página.'
                : 'Só um instante...';
        });
        xhr.addEventListener('load', function () {
            pararDeAvisarAntesDeSair();
            // Volta pra MESMA aba/página de onde o formulário foi enviado --
            // xhr.responseURL nunca inclui o #fragmento do Location (fragmento
            // é só client-side, o navegador não manda pro servidor e a Graph
            // API/redirect não devolve ele em lugar nenhum que o XHR exponha),
            // então sem isso qualquer formulário com anexo mandava a pessoa
            // pra aba errada mesmo o backend redirecionando certo -- bug real
            // reportado pelo Kleber (respondia um chamado com anexo e caía na
            // Visão Geral em vez de continuar em Chamados). Reconstrói o
            // fragmento a partir do MESMO campo aba_retorno que o backend usa
            // pra montar o Location, ou mantém o hash que já estava na tela
            // se o formulário não tiver esse campo.
            var abaRetorno = dados.get('aba_retorno');
            var baseFinal = (xhr.responseURL || window.location.href).split('#')[0];
            var hashFinal = abaRetorno || window.location.hash.replace(/^#/, '');

            // Quando a base (caminho+query) não muda -- ex.: envio feito de
            // dentro da MESMA aba que ele volta pra depois (Fontes ADVPL,
            // Documentos, etc. dentro da aba "setores") -- trocar só o
            // #fragmento é navegação same-document pro navegador (não
            // recarrega a página, só rola até o elemento). Resultado: o
            // overlay "Registrando..." ficava preso na tela pra sempre
            // porque a página nunca buscava os dados novos do servidor.
            // Bug real reportado pelo Kleber (upload de fonte ADVPL
            // registrava certinho no banco mas o modal nunca sumia).
            // location.reload() força a busca de verdade; o #fragmento
            // certo precisa já estar setado ANTES de recarregar, senão o
            // reload volta pro fragmento antigo.
            if (baseFinal === window.location.href.split('#')[0]) {
                if (window.location.hash !== '#' + hashFinal) {
                    window.location.hash = hashFinal;
                }
                window.location.reload();
            } else {
                window.location.href = baseFinal + (hashFinal ? '#' + hashFinal : '');
            }
        });
        xhr.addEventListener('error', function () {
            pararDeAvisarAntesDeSair();
            overlay.classList.remove('show');
            alert('Falha ao enviar o arquivo. Verifique sua conexão e tente novamente.');
        });
        xhr.addEventListener('timeout', function () {
            pararDeAvisarAntesDeSair();
            overlay.classList.remove('show');
            alert('O envio demorou demais e foi cancelado. Verifique sua conexão e tente novamente -- se o arquivo for muito grande, tente com uma internet mais estável.');
        });
        xhr.send(dados);
    });

    // API mínima exposta pra scripts inline de cada página (ex.: ativar a
    // aba certa com base no hash da URL).
    window.PrimeUI = {
        showTab: mostrarAba,
        openModal: abrirModal,
        closeModal: fecharModal,
        formatarTelefone: formatarTelefone,
    };
})();
