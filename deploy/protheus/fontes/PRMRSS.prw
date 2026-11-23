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
