/**
 * Calendário visual mensal/semanal/diário reutilizável -- extraído de
 * cliente.php pra poder ser usado também no portal (visão só-leitura pro
 * cliente, ver portal/calendario.php). Cada .calendario-mensal encontrado
 * na página tem seu próprio estado de mês/ano em memória (não recarrega a
 * página pra navegar, os eventos já vêm todos no data-eventos).
 *
 * opcoes.aoAbrirNovo(container, dataIso) e opcoes.aoAbrirEvento(evento,
 * abaRetorno) são OPCIONAIS -- se não vierem (caso do portal), clicar num
 * dia ou evento simplesmente não faz nada, o que já entrega o modo
 * somente-leitura sem precisar de uma flag "readOnly" separada.
 */
function escaparHtmlCalendario(texto) {
    var div = document.createElement('div');
    div.textContent = texto || '';
    return div.innerHTML;
}

function inicializarCalendarioMensal(container, opcoes) {
    opcoes = opcoes || {};
    var eventos = [];
    try { eventos = JSON.parse(container.getAttribute('data-eventos') || '[]'); } catch (e) { eventos = []; }

    var eventosPorDia = {};
    eventos.forEach(function (ev) {
        if (!ev.data_inicio) return;
        (eventosPorDia[ev.data_inicio] = eventosPorDia[ev.data_inicio] || []).push(ev);
    });

    var hoje = new Date();
    var modoAtual = 'mes'; // 'mes' | 'semana' | 'dia'
    var dataFoco = new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate());

    var nomesMeses = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    var nomesDiasSemana = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    var nomesDiasSemanaCompleto = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }
    function paraIso(data) { return data.getFullYear() + '-' + pad2(data.getMonth() + 1) + '-' + pad2(data.getDate()); }
    function mesmoDia(a, b) { return a.toDateString() === b.toDateString(); }

    // Mesma paleta/regra de CORES_SETOR em src/helpers.php (cor_setor()).
    var CORES_SETOR_JS = ['#4f46e5', '#0891b2', '#be123c', '#b45309', '#15803d', '#7c3aed', '#0e7490', '#c2410c'];
    function corSetorEvento(setorId) {
        if (!setorId) return null;
        return CORES_SETOR_JS[setorId % CORES_SETOR_JS.length];
    }

    function classeTipoEvento(ev) {
        if (ev.status === 'cancelado') return 'calendario-evento-cancelado';
        return 'calendario-evento-' + (['reuniao', 'conversa', 'auxilio'].indexOf(ev.tipo) !== -1 ? ev.tipo : 'treinamento');
    }

    function construirChipEvento(ev) {
        var horaTexto = ev.hora_inicio ? ev.hora_inicio.substring(0, 5) + ' ' : '';
        var cor = ev.status !== 'cancelado' ? corSetorEvento(ev.setor_id) : null;
        var estilo = cor ? ' style="background-color:' + cor + '"' : '';
        return '<span class="calendario-evento ' + classeTipoEvento(ev) + '" data-evento-id="' + ev.id + '"' + estilo + ' title="' + escaparHtmlCalendario(horaTexto + ev.titulo) + '">'
            + escaparHtmlCalendario(horaTexto + ev.titulo) + '</span>';
    }

    function construirItemEvento(ev) {
        var horaTexto = ev.hora_inicio ? ev.hora_inicio.substring(0, 5) : '--:--';
        var cor = ev.status !== 'cancelado' ? corSetorEvento(ev.setor_id) : null;
        var estilo = cor ? ' style="border-left-color:' + cor + ';background-color:' + cor + '1a"' : '';
        return '<div class="calendario-evento-item ' + classeTipoEvento(ev) + '" data-evento-id="' + ev.id + '"' + estilo + '>'
            + '<span class="calendario-evento-item-hora">' + escaparHtmlCalendario(horaTexto) + '</span>'
            + '<span class="calendario-evento-item-titulo">' + escaparHtmlCalendario(ev.titulo) + '</span>'
            + '</div>';
    }

    function eventosOrdenados(iso) {
        var lista = (eventosPorDia[iso] || []).slice();
        lista.sort(function (a, b) { return (a.hora_inicio || '').localeCompare(b.hora_inicio || ''); });
        return lista;
    }

    function renderizarMes() {
        var ano = dataFoco.getFullYear(), mes = dataFoco.getMonth();
        var primeiroDiaMes = new Date(ano, mes, 1);
        var diaSemanaInicio = primeiroDiaMes.getDay();
        var diasNoMes = new Date(ano, mes + 1, 0).getDate();
        var diasMesAnterior = new Date(ano, mes, 0).getDate();
        var totalCelulas = diaSemanaInicio + diasNoMes;
        var linhas = Math.ceil(totalCelulas / 7);
        var totalCelulasGrid = linhas * 7;

        var corpo = '<div class="calendario-grid">';
        nomesDiasSemana.forEach(function (nome) {
            corpo += '<div class="calendario-dia-semana">' + nome + '</div>';
        });

        for (var i = 0; i < totalCelulasGrid; i++) {
            var numeroDia, mesCelula, foraDoMes;
            if (i < diaSemanaInicio) {
                numeroDia = diasMesAnterior - (diaSemanaInicio - 1 - i);
                mesCelula = mes - 1;
                foraDoMes = true;
            } else if (i >= diaSemanaInicio + diasNoMes) {
                numeroDia = i - (diaSemanaInicio + diasNoMes) + 1;
                mesCelula = mes + 1;
                foraDoMes = true;
            } else {
                numeroDia = i - diaSemanaInicio + 1;
                mesCelula = mes;
                foraDoMes = false;
            }

            var dataCelula = new Date(ano, mesCelula, numeroDia);
            var iso = paraIso(dataCelula);
            var ehHoje = !foraDoMes && mesmoDia(dataCelula, hoje);

            corpo += '<div class="calendario-dia' + (foraDoMes ? ' calendario-fora-do-mes' : '') + (ehHoje ? ' calendario-hoje' : '') + '" data-abrir-novo="' + iso + '">';
            corpo += '<span class="calendario-numero-dia" data-ir-dia="' + iso + '">' + dataCelula.getDate() + '</span>';

            var eventosDoDia = eventosOrdenados(iso);
            var maxMostrar = 2;
            eventosDoDia.slice(0, maxMostrar).forEach(function (ev) { corpo += construirChipEvento(ev); });
            if (eventosDoDia.length > maxMostrar) {
                corpo += '<span class="calendario-mais-eventos" data-ir-dia="' + iso + '">+' + (eventosDoDia.length - maxMostrar) + ' mais</span>';
            }
            corpo += '</div>';
        }
        corpo += '</div>';

        return { titulo: nomesMeses[mes] + ' de ' + ano, corpo: corpo };
    }

    function renderizarSemana() {
        var inicioSemana = new Date(dataFoco);
        inicioSemana.setDate(dataFoco.getDate() - dataFoco.getDay());
        var fimSemana = new Date(inicioSemana);
        fimSemana.setDate(inicioSemana.getDate() + 6);

        var corpo = '<div class="calendario-semana-grid">';
        for (var i = 0; i < 7; i++) {
            var dia = new Date(inicioSemana);
            dia.setDate(inicioSemana.getDate() + i);
            var iso = paraIso(dia);
            var ehHoje = mesmoDia(dia, hoje);

            corpo += '<div class="calendario-semana-coluna' + (ehHoje ? ' calendario-hoje' : '') + '" data-abrir-novo="' + iso + '">';
            corpo += '<div class="calendario-semana-cabecalho-dia" data-ir-dia="' + iso + '">'
                + '<span class="calendario-semana-nome-dia">' + nomesDiasSemana[i] + '</span>'
                + '<span class="calendario-numero-dia">' + dia.getDate() + '</span>'
                + '</div>';

            eventosOrdenados(iso).forEach(function (ev) { corpo += construirChipEvento(ev); });
            corpo += '</div>';
        }
        corpo += '</div>';

        return {
            titulo: inicioSemana.getDate() + ' a ' + fimSemana.getDate() + ' de ' + nomesMeses[fimSemana.getMonth()] + ' de ' + fimSemana.getFullYear(),
            corpo: corpo,
        };
    }

    function renderizarDia() {
        var iso = paraIso(dataFoco);
        var eventosDoDia = eventosOrdenados(iso);

        var corpo = '<div class="calendario-dia-lista">';
        if (!eventosDoDia.length) {
            corpo += '<div class="calendario-dia-vazio" data-abrir-novo="' + iso + '">' + (opcoes.aoAbrirNovo ? 'Nenhum treinamento ou reunião neste dia. Clique aqui para criar um.' : 'Nenhum treinamento ou reunião neste dia.') + '</div>';
        } else {
            eventosDoDia.forEach(function (ev) { corpo += construirItemEvento(ev); });
        }
        corpo += '</div>';

        return {
            titulo: nomesDiasSemanaCompleto[dataFoco.getDay()] + ', ' + dataFoco.getDate() + ' de ' + nomesMeses[dataFoco.getMonth()] + ' de ' + dataFoco.getFullYear(),
            corpo: corpo,
        };
    }

    function irParaDia(iso) {
        var partes = iso.split('-');
        dataFoco = new Date(parseInt(partes[0], 10), parseInt(partes[1], 10) - 1, parseInt(partes[2], 10));
        modoAtual = 'dia';
        renderizar();
    }

    function renderizar() {
        var resultado = modoAtual === 'semana' ? renderizarSemana() : (modoAtual === 'dia' ? renderizarDia() : renderizarMes());

        var rotulosModo = { mes: 'Mês', semana: 'Semana', dia: 'Dia' };
        var modosHtml = ['mes', 'semana', 'dia'].map(function (m) {
            return '<button type="button" class="calendario-modo-botao' + (modoAtual === m ? ' active' : '') + '" data-modo="' + m + '">' + rotulosModo[m] + '</button>';
        }).join('');

        container.innerHTML = '<div class="calendario-cabecalho">'
            + '<div class="d-flex align-items-center gap-2">'
            + '<button type="button" class="calendario-nav-botao" data-nav="-1" aria-label="Anterior">&lsaquo;</button>'
            + '<span class="calendario-titulo-mes">' + resultado.titulo + '</span>'
            + '<button type="button" class="calendario-nav-botao" data-nav="1" aria-label="Próximo">&rsaquo;</button>'
            + '</div>'
            + '<div class="calendario-modos">' + modosHtml + '</div>'
            + '</div>' + resultado.corpo;
    }

    // Delegado num único listener no container (sobrevive aos re-renders,
    // já que innerHTML muda mas o container em si não).
    container.addEventListener('click', function (e) {
        var navEl = e.target.closest('[data-nav]');
        if (navEl) {
            var dir = parseInt(navEl.getAttribute('data-nav'), 10);
            if (modoAtual === 'semana') dataFoco.setDate(dataFoco.getDate() + dir * 7);
            else if (modoAtual === 'dia') dataFoco.setDate(dataFoco.getDate() + dir);
            else { dataFoco.setDate(1); dataFoco.setMonth(dataFoco.getMonth() + dir); }
            renderizar();
            return;
        }

        var modoEl = e.target.closest('[data-modo]');
        if (modoEl) {
            modoAtual = modoEl.getAttribute('data-modo');
            renderizar();
            return;
        }

        var chipEl = e.target.closest('[data-evento-id]');
        if (chipEl) {
            if (!opcoes.aoAbrirEvento) return;
            var eventoId = parseInt(chipEl.getAttribute('data-evento-id'), 10);
            var evento = eventos.filter(function (ev) { return ev.id === eventoId; })[0];
            if (evento) opcoes.aoAbrirEvento(evento, container.getAttribute('data-aba-retorno'));
            return;
        }

        var diaIrEl = e.target.closest('[data-ir-dia]');
        if (diaIrEl) {
            irParaDia(diaIrEl.getAttribute('data-ir-dia'));
            return;
        }

        var novoEl = e.target.closest('[data-abrir-novo]');
        if (novoEl) {
            if (!opcoes.aoAbrirNovo) return;
            opcoes.aoAbrirNovo(container, novoEl.getAttribute('data-abrir-novo'));
            return;
        }
    });

    renderizar();
}
