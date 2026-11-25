#include 'protheus.ch'

// Constantes do MVC (fwmvcdef.ch nao esta no path deste ambiente);
// valores padrao TOTVS para as operacoes.
#ifndef MODEL_OPERATION_INSERT
    #define MODEL_OPERATION_INSERT 3
#endif
#ifndef MODEL_OPERATION_UPDATE
    #define MODEL_OPERATION_UPDATE 4
#endif

/*/{Protheus.doc} PRMRSSLIB
Biblioteca de funcoes de manutencao de usuarios (reset/criar/apagar) via
FWUSERACCOUNTDATA, usada pelo servico REST PRMRSS (fonte PRMRSS.prw).
Fica em fonte separado para poder ser recompilada sem re-registrar o
servidor REST (o que acontece ao recompilar um WSRESTFUL).

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/

/*/{Protheus.doc} U_PRMRST
Reseta a senha de um usuario Protheus via FWUSERACCOUNTDATA, sem tela.
Roda em contexto REST (ambiente PRIMEERP ja preparado pelo servidor).
Retorna .T./.F. e devolve a mensagem de erro por referencia.

@param  cLoginUsr  Login do usuario (campo USR_CODE)
@param  cSenhaUsr  Nova senha em texto
@param  cErro      (referencia) mensagem de erro, se houver

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMRST(cLoginUsr, cSenhaUsr, cErro)
    Local oModel
    Local lRet := .F.
    Local aErro
    // Variaveis do ambiente transacional/log que o contexto REST nao inicializa
    // (BeginTran/RecLock/GravaLog em APLIB060). Declaradas por escopo dinamico.
    Private __TTSINUSE := 0
    Private __TTSKEEP  := .F.
    Private __TTSPUSH  := {}
    Private __TTSBREAK := {}
    Private __CLOGSIGA := ""

    Default cLoginUsr := ""
    Default cSenhaUsr := ""
    Default cErro     := ""
    cErro := ""

    If Empty(cLoginUsr)
        cErro := "Login nao informado"
        Return .F.
    EndIf

    // tenta por nome de login (ordem 2) e, se nao achar, por codigo/ID (ordem 1)
    PswOrder(2)
    If !PswSeek(AllTrim(cLoginUsr), .T.)
        PswOrder(1)
        If !PswSeek(AllTrim(cLoginUsr), .T.)
            cErro := "Usuario nao encontrado: " + AllTrim(cLoginUsr)
            Return .F.
        EndIf
    EndIf

    oModel := FWLoadModel("FWUSERACCOUNTDATA")
    oModel:SetOperation(MODEL_OPERATION_UPDATE)
    oModel:Activate()

    oModel:SetValue("DATAUSER", "USR_PSW", cSenhaUsr)
    oModel:SetValue("DATAUSER", "USR_PSWCMP", cSenhaUsr)

    If oModel:VldData()
        oModel:CommitData()
        lRet := .T.
    Else
        aErro := oModel:GetErrorMessage()
        cErro := "Falha ao gravar"
        If ValType(aErro) == "A" .And. Len(aErro) >= 6
            cErro += ": " + cValToChar(aErro[6])
        EndIf
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet

/*/{Protheus.doc} U_PRMNEWU
Cria (ou atualiza, se ja existir) um usuario Protheus via FWUSERACCOUNTDATA,
sem tela. Roda em contexto REST (ambiente ja preparado pelo servidor).
Retorna .T./.F. e devolve a mensagem de erro por referencia.

@param  cLogin  Login do usuario (USR_CODIGO)
@param  cNome   Nome completo (USR_NOME)
@param  cSenha  Senha em texto
@param  cEmail  E-mail (USR_EMAIL) - opcional
@param  cErro   (referencia) mensagem de erro, se houver

@author  Kleber Metzger
@since   25/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMNEWU(cLogin, cNome, cSenha, cEmail, cErro)
    Local oModel
    Local lRet    := .F.
    Local lExiste
    Local nOper
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

    If Empty(cLogin)
        cErro := "Login nao informado"
        Return .F.
    EndIf
    If Empty(cNome)
        cErro := "Nome nao informado"
        Return .F.
    EndIf

    // Ja existe? Entao e atualizacao (nome/senha/email); senao, inclusao.
    PswOrder(2)
    lExiste := PswSeek(AllTrim(cLogin), .T.)
    If !lExiste
        PswOrder(1)
        lExiste := PswSeek(AllTrim(cLogin), .T.)
    EndIf
    nOper := IIf(lExiste, MODEL_OPERATION_UPDATE, MODEL_OPERATION_INSERT)

    oModel := FWLoadModel("FWUSERACCOUNTDATA")
    oModel:SetOperation(nOper)
    oModel:Activate()

    oModel:SetValue("DATAUSER", "USR_CODIGO", AllTrim(cLogin))
    oModel:SetValue("DATAUSER", "USR_NOME",   cNome)
    If !Empty(cSenha)
        oModel:SetValue("DATAUSER", "USR_PSW",    cSenha)
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
        cErro := IIf(lExiste, "Falha ao atualizar", "Falha ao criar")
        If ValType(aErro) == "A" .And. Len(aErro) >= 6
            cErro += ": " + cValToChar(aErro[6])
        EndIf
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet
