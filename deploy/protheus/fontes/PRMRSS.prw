#include 'protheus.ch'
#include 'restful.ch'

#define PR_CHAVE_RESET "PrimeHub@2026"

/*/{Protheus.doc} PRMRSS
Reset de senha de usuario Protheus via REST.
Consumido pelo Prime Hub (agente administrador) para reiniciar a
senha de um usuario sem necessidade de tela, de forma automatica.

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/

WSRESTFUL PRMRSS DESCRIPTION "PrimeERP - Reset de senha"
    WSDATA login  AS STRING
    WSMETHOD POST DESCRIPTION "Reseta a senha do usuario informado" WSSYNTAX "/prmrss"
END WSRESTFUL

//-------------------------------------------------------------------
// POST  body JSON: { "login": "usuario", "senha": "novaSenha", "chave": "token" }
//-------------------------------------------------------------------
WSMETHOD POST WSSERVICE PRMRSS
    Local oJson   := JsonObject():New()
    Local oResp   := JsonObject():New()
    Local cBody   := ::GetContent()
    Local cErroP  := ""
    Local cLogin  := ""
    Local cSenha  := ""
    Local cChave  := ""
    Local cAcao   := ""
    Local lOk     := .F.

    ::SetContentType("application/json")

    cErroP := oJson:FromJson(cBody)
    If !Empty(cErroP)
        oResp['ok']   := .F.
        oResp['erro'] := "JSON invalido: " + cErroP
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    cLogin := AllTrim(cValToChar(oJson:GetJsonObject('login')))
    cSenha := cValToChar(oJson:GetJsonObject('senha'))
    cChave := AllTrim(cValToChar(oJson:GetJsonObject('chave')))
    cAcao  := Lower(AllTrim(cValToChar(oJson:GetJsonObject('acao'))))
    If Empty(cAcao)
        cAcao := "reset"
    EndIf

    If cChave != PR_CHAVE_RESET
        ::SetStatus(401)
        oResp['ok']   := .F.
        oResp['erro'] := "Chave de autorizacao invalida"
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    If Empty(cLogin)
        oResp['ok']   := .F.
        oResp['erro'] := "Login nao informado"
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    // criar/apagar so e permitido para logins de teste (prefixo TST)
    If (cAcao == "criar" .Or. cAcao == "apagar") .And. Upper(Left(cLogin,3)) != "TST"
        oResp['ok']   := .F.
        oResp['erro'] := "Acao '" + cAcao + "' so permitida para logins de teste (prefixo TST)"
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    Do Case
        Case cAcao == "criar"
            lOk := U_PRMNEWU(cLogin, "Usuario de teste PrimeERP", cSenha, @cErroP)
        Case cAcao == "apagar"
            lOk := U_PRMDELU(cLogin, @cErroP)
        Otherwise
            lOk := U_PRMRSS(cLogin, cSenha, @cErroP)
    EndCase

    oResp['ok']    := lOk
    oResp['acao']  := cAcao
    oResp['login'] := cLogin
    oResp['erro']  := cErroP
    ::SetResponse(oResp:ToJson())

Return lOk

/*/{Protheus.doc} U_PRMRSS
Reseta a senha de um usuario Protheus via FWUSERACCOUNTDATA, sem tela.
Prepara o ambiente (empresa/filial detectadas pela SM0) pois roda em
contexto REST/job sem ambiente logado. Retorna .T./.F. e devolve a
mensagem de erro por referencia.

@param  cLoginUsr  Login do usuario (campo USR_CODE)
@param  cSenhaUsr  Nova senha em texto
@param  cErro      (referencia) mensagem de erro, se houver

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMRSS(cLoginUsr, cSenhaUsr, cErro)
    Local oModel
    Local lRet    := .F.
    Local aErro

    Default cLoginUsr := ""
    Default cSenhaUsr := ""
    Default cErro     := ""

    cErro := ""

    If Empty(cLoginUsr)
        cErro := "Login nao informado"
        Return .F.
    EndIf

    // posiciona no usuario para o model carregar o registro certo
    PswOrder(1)
    If !PswSeek(AllTrim(cLoginUsr), .T.)
        cErro := "Usuario nao encontrado: " + AllTrim(cLoginUsr)
        Return .F.
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
        lRet  := .F.
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet

/*/{Protheus.doc} U_PRMNEWU
Cria um usuario de teste via FWUSERACCOUNTDATA (INSERT). Uso interno de
validacao/QA. Retorna .T./.F. e a mensagem de erro por referencia.

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMNEWU(cCode, cName, cSenha, cErro)
    Local oModel
    Local lRet := .F.
    Local aErro

    Default cCode  := ""
    Default cName  := "Usuario de teste"
    Default cSenha := ""
    Default cErro  := ""
    cErro := ""

    PswOrder(1)
    If PswSeek(AllTrim(cCode), .T.)
        cErro := "Usuario ja existe: " + AllTrim(cCode)
        Return .F.
    EndIf

    oModel := FWLoadModel("FWUSERACCOUNTDATA")
    oModel:SetOperation(MODEL_OPERATION_INSERT)
    oModel:Activate()

    oModel:SetValue("DATAUSER", "USR_CODE", cCode)
    oModel:SetValue("DATAUSER", "USR_NAME", cName)
    oModel:SetValue("DATAUSER", "USR_PSW", cSenha)
    oModel:SetValue("DATAUSER", "USR_PSWCMP", cSenha)

    If oModel:VldData()
        oModel:CommitData()
        lRet := .T.
    Else
        aErro := oModel:GetErrorMessage()
        cErro := "Falha ao criar"
        If ValType(aErro) == "A" .And. Len(aErro) >= 6
            cErro += ": " + cValToChar(aErro[6])
        EndIf
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet

/*/{Protheus.doc} U_PRMDELU
Apaga um usuario de teste via FWUSERACCOUNTDATA (DELETE). Uso interno de
validacao/QA. Retorna .T./.F. e a mensagem de erro por referencia.

@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMDELU(cCode, cErro)
    Local oModel
    Local lRet := .F.
    Local aErro

    Default cCode := ""
    Default cErro := ""
    cErro := ""

    PswOrder(1)
    If !PswSeek(AllTrim(cCode), .T.)
        cErro := "Usuario nao encontrado: " + AllTrim(cCode)
        Return .F.
    EndIf

    oModel := FWLoadModel("FWUSERACCOUNTDATA")
    oModel:SetOperation(MODEL_OPERATION_DELETE)
    oModel:Activate()

    If oModel:VldData()
        oModel:CommitData()
        lRet := .T.
    Else
        aErro := oModel:GetErrorMessage()
        cErro := "Falha ao apagar"
        If ValType(aErro) == "A" .And. Len(aErro) >= 6
            cErro += ": " + cValToChar(aErro[6])
        EndIf
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet
