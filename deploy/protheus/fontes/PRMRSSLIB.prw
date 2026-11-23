#include 'protheus.ch'
#include 'fwmvcdef.ch'

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

/*/{Protheus.doc} U_PRMRSS
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
User Function PRMRSS(cLoginUsr, cSenhaUsr, cErro)
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
    EndIf

    oModel:DeActivate()
    oModel:Destroy()

Return lRet

/*/{Protheus.doc} U_PRMNEWU
Cria um usuario de teste via FWUSERACCOUNTDATA (INSERT). Uso interno de QA.
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
Apaga um usuario de teste via FWUSERACCOUNTDATA (DELETE). Uso interno de QA.
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
