#include 'protheus.ch'

/*/{Protheus.doc} PRMADMLIB
Biblioteca das acoes administrativas do PrimeERP (Prime Hub), chamada pelo
servico REST PRMADM (fonte PRMADM.prw). Fonte separado para recompilar sem
re-registrar o WSRESTFUL.

Acoes:
  - criar_usuario : cria/atualiza usuario Protheus (usa U_PRMNEWU do PRMRSSLIB).
  - menu          : cria/altera item de menu (EM IMPLEMENTACAO - valida mecanismo
                    da versao do cliente antes de gravar).

@author  Kleber Metzger
@since   25/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/

/*/{Protheus.doc} U_PRMADM
Roteia a acao recebida pelo REST para a funcao correspondente e preenche
o objeto de resposta (oResp) com ok/erro e dados.

@param  cAcao  Acao solicitada (ex.: "criar_usuario")
@param  oJson  JsonObject com os parametros do corpo
@param  oResp  JsonObject de resposta (preenchido por referencia)
/*/
User Function PRMADM(cAcao, oJson, oResp)
    Local cErro := ""
    Local lOk   := .F.

    Do Case
    Case cAcao == "criar_usuario"
        lOk := PRMADMUsr(oJson, oResp, @cErro)

    Case cAcao == "menu"
        // EM IMPLEMENTACAO: gravar em MPMENU_* varia por versao; nao grava as cegas.
        oResp['ok']   := .F.
        oResp['erro'] := "Acao 'menu' ainda em implementacao - sera liberada apos validacao na base."
        Return .F.

    Otherwise
        oResp['ok']   := .F.
        oResp['erro'] := "Acao desconhecida: " + cAcao
        Return .F.
    EndCase

    oResp['ok']   := lOk
    oResp['erro'] := cErro

Return lOk

//-------------------------------------------------------------------
// criar_usuario : le os campos do JSON e chama U_PRMNEWU (PRMRSSLIB).
//-------------------------------------------------------------------
Static Function PRMADMUsr(oJson, oResp, cErro)
    Local cLogin := AllTrim(cValToChar(oJson:GetJsonObject('login')))
    Local cNome  := cValToChar(oJson:GetJsonObject('nome'))
    Local cSenha := cValToChar(oJson:GetJsonObject('senha'))
    Local cEmail := cValToChar(oJson:GetJsonObject('email'))
    Local lOk    := .F.

    If Empty(cLogin)
        cErro := "Login nao informado"
        Return .F.
    EndIf

    lOk := U_PRMNEWU(cLogin, cNome, cSenha, cEmail, @cErro)
    If lOk
        oResp['login'] := cLogin
    EndIf

Return lOk
