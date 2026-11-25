#include 'protheus.ch'
#include 'restful.ch'

#define PR_CHAVE_ADM "PrimeHub@2026"

/*/{Protheus.doc} PRMADM
Servico REST administrativo do PrimeERP (Prime Hub). Executa operacoes de
administracao no Protheus do cliente sem tela e sem acesso direto ao banco:
hoje criar/atualizar usuario; menu em implementacao. Consumido pelo portal
(agente Gemini), que nunca acessa o banco do cliente diretamente.

Autorizacao por chave no corpo. A logica fica em PRMADMLIB.prw (fonte
separado) para recompilar sem re-registrar o WSRESTFUL.

@author  Kleber Metzger
@since   25/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/

WSRESTFUL PRMADM DESCRIPTION "PrimeERP - Administracao"
    WSDATA acao AS STRING
    WSMETHOD POST DESCRIPTION "Executa uma acao administrativa" WSSYNTAX "/prmadm"
END WSRESTFUL

//-------------------------------------------------------------------
// POST body JSON:
//   { "acao":"criar_usuario", "chave":"...", "login":"...", "nome":"...",
//     "senha":"...", "email":"..." }
//   { "acao":"menu", "chave":"...", ... }  (em implementacao)
//-------------------------------------------------------------------
WSMETHOD POST WSSERVICE PRMADM
    Local oJson := JsonObject():New()
    Local oResp := JsonObject():New()
    Local cBody := ::GetContent()
    Local cErroP := ""
    Local cAcao  := ""
    Local cChave := ""

    ::SetContentType("application/json")

    cErroP := oJson:FromJson(cBody)
    If !Empty(cErroP)
        oResp['ok']   := .F.
        oResp['erro'] := "JSON invalido: " + cErroP
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    cAcao  := AllTrim(Lower(cValToChar(oJson:GetJsonObject('acao'))))
    cChave := AllTrim(cValToChar(oJson:GetJsonObject('chave')))

    If cChave != PR_CHAVE_ADM
        ::SetStatus(401)
        oResp['ok']   := .F.
        oResp['erro'] := "Chave de autorizacao invalida"
        ::SetResponse(oResp:ToJson())
        Return .F.
    EndIf

    // Roteia a acao para a biblioteca (fonte separado).
    U_PRMADM(cAcao, oJson, oResp)

    ::SetResponse(oResp:ToJson())

Return oResp:GetJsonObject('ok') == .T.
