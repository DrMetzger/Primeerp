#Include "TOTVS.ch"
#Include "FWMVCDef.ch"

/*/{Protheus.doc} PRMRSS
Reseta a senha de um usuario do Protheus SEM a senha antiga (uso administrativo
da PrimeERP, ex.: usuario esqueceu a senha). Baseado na tecnica do ExecAuto do
FWUSERACCOUNTDATA. Retorna .T. quando reseta.
@type  Function
@author  Kleber Metzger
@since   23/11/2026
@version P12
@obs     PrimeERP - Solucoes em Tecnologia
/*/
User Function PRMRSS(cLoginUsr, cSenhaUsr)
    Local cAliasUsr := "MPUSR_USR"
    Local lDeuCerto := .F.
    Local oModel

    cLoginUsr := AllTrim(cLoginUsr)
    cSenhaUsr := AllTrim(cSenhaUsr)

    DbSelectArea(cAliasUsr)
    (cAliasUsr)->(DbSetOrder(2)) // USR_CODIGO

    If (cAliasUsr)->(MsSeek(cLoginUsr))
        Set Filter To &("USR_CODIGO == '" + cLoginUsr + "'")

        oModel := FWLoadModel("FWUSERACCOUNTDATA")
        oModel:SetOperation(MODEL_OPERATION_UPDATE)
        oModel:Activate()

        oModel:SetValue("DATAUSER", "USR_PSW",    cSenhaUsr)
        oModel:SetValue("DATAUSER", "USR_PSWCMP", cSenhaUsr)

        If oModel:VldData() .And. oModel:CommitData()
            lDeuCerto := .T.
        EndIf

        oModel:DeActivate()
        FreeObj(oModel)
        DbSelectArea(cAliasUsr)
        Set Filter To
    EndIf
Return lDeuCerto
