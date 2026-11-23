#include 'protheus.ch'

// Constante do MVC (fwmvcdef.ch nao esta no path deste ambiente);
// valor padrao TOTVS para operacao de alteracao.
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

    Default cLoginUsr := ""
    Default cSenhaUsr := ""
    Default cErro     := ""
    cErro := ""

    If Empty(cLoginUsr)
        cErro := "Login nao informado"
        Return .F.
    EndIf

    // O ambiente (empresa 99 / filial 01) e o controle transacional sao
    // preparados pelo PrepareIn configurado na URI REST (appserver_rest.ini),
    // na thread da requisicao, antes deste handler.

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
