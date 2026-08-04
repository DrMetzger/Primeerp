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

    // API mínima exposta pra scripts inline de cada página (ex.: ativar a
    // aba certa com base no hash da URL).
    window.PrimeUI = {
        showTab: mostrarAba,
        openModal: abrirModal,
        closeModal: fecharModal,
    };
})();
