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

    lOk := U_PRMRSS(cLogin, cSenha, @cErroP)

    oResp['ok']    := lOk
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
