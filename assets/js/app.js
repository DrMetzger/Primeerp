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

        var conteudo = painel.closest('.tab-content') || document;
        conteudo.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('show', 'active'); });
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

    function abrirModal(modalEl) {
        if (!modalEl) return;
        modalEl.dispatchEvent(new CustomEvent('show.bs.modal', { bubbles: true }));
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
        criarBackdrop();
        modalEl.dispatchEvent(new CustomEvent('shown.bs.modal', { bubbles: true }));
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
            abrirModal(sel ? document.querySelector(sel) : null);
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

    // API mínima exposta pra scripts inline de cada página (ex.: ativar a
    // aba certa com base no hash da URL).
    window.PrimeUI = {
        showTab: mostrarAba,
        openModal: abrirModal,
        closeModal: fecharModal,
        formatarTelefone: formatarTelefone,
    };
})();
