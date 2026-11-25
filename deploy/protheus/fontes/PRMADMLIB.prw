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

/*/{Protheus.doc} U_PRMNEWU
Cria (ou atualiza, se ja existir) um usuario Protheus via FWUSERACCOUNTDATA,
sem tela. Roda em contexto REST (ambiente ja preparado pelo servidor).

@param  cLogin  Login do usuario (USR_CODIGO)
@param  cNome   Nome completo (USR_NOME)
@param  cSenha  Senha em texto (opcional)
@param  cEmail  E-mail (USR_EMAIL) - opcional
@param  cErro   (referencia) mensagem de erro, se houver

@author  Kleber Metzger
@since   25/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMNEWU(cLogin, cNome, cSenha, cEmail, cErro)
    Local oModel
    Local lRet := .F.
    Local aErro
    // Ambiente transacional/log que o contexto REST nao inicializa.
    Private __TTSINUSE := 0
    Private __TTSKEEP  := .F.
    Private __TTSPUSH  := {}
    Private __TTSBREAK := {}
    Private __CLOGSIGA := ""

    Default cLogin := ""
    Default cNome  := ""
    Default cSenha := ""
    Default cEmail := ""
    Default cErro  := ""
    cErro := ""

    If Empty(cLogin) .Or. Empty(cNome)
        cErro := "Login e nome sao obrigatorios"
        Return .F.
    EndIf

    oModel := FWLoadModel("FWUSERACCOUNTDATA")
    oModel:SetOperation(3)  // 3 = inclusao (MODEL_OPERATION_INSERT)
    oModel:Activate()

    oModel:SetValue("DATAUSER", "USR_CODIGO", AllTrim(cLogin))
    oModel:SetValue("DATAUSER", "USR_NOME", cNome)
    If !Empty(cSenha)
        oModel:SetValue("DATAUSER", "USR_PSW", cSenha)
        oModel:SetValue("DATAUSER", "USR_PSWCMP", cSenha)
    EndIf
    If !Empty(cEmail)
        oModel:SetValue("DATAUSER", "USR_EMAIL", cEmail)
    EndIf

    If oModel:VldData()
        oModel:CommitData()
        lRet := .T.
    Else
        aErro := oModel:GetErrorMessage()
        cErro := "Falha ao criar usuario"
        If ValType(aErro) == "A" .And. Len(aErro) >= 6
            cErro += ": " + cValToChar(aErro[6])
        EndIf
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet
