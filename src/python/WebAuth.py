from pprint import pprint
import json
import sys

HOST = model.Setting("wp_host")
WP_LOGIN_PATH = model.Setting("wp_loginPath", "/wp-login.php")
WP_USER_EV_KEY = "WordPress User ID"

def sysObjToStdObj(obj, depth):
	t = type(obj).__name__

	if t in ["str", "int", "bool", "DateTime", "NoneType", "Decimal"]:
		return obj

	if t == "Person":
		r = {
			"_class": t,
			"wpid": model.ExtraValueInt(obj.PeopleId, WP_USER_EV_KEY),
			"PeopleId": sysObjToStdObj(obj.PeopleId, depth-1),
			"FamilyId": sysObjToStdObj(obj.FamilyId, depth-1),
			"NickName": sysObjToStdObj(obj.NickName, depth-1),
			"TitleCode": sysObjToStdObj(obj.TitleCode, depth-1),
			"FirstName": sysObjToStdObj(obj.FirstName, depth-1),
			"MiddleName": sysObjToStdObj(obj.MiddleName, depth-1),
			"LastName": sysObjToStdObj(obj.LastName, depth-1),
			"Name": sysObjToStdObj(obj.Name, depth-1),
			"SuffixCode": sysObjToStdObj(obj.SuffixCode, depth-1),
			"AltName": sysObjToStdObj(obj.AltName, depth-1),
			"MaidenName": sysObjToStdObj(obj.MaidenName, depth-1),
			"GenderId": sysObjToStdObj(obj.GenderId, depth-1),
			"HomePhone": sysObjToStdObj(obj.HomePhone, depth-1),
			"CellPhone": sysObjToStdObj(obj.CellPhone, depth-1),
			"WorkPhone": sysObjToStdObj(obj.WorkPhone, depth-1),
			"EmailAddress": sysObjToStdObj(obj.EmailAddress, depth-1),
			"SendEmailAddress1": sysObjToStdObj(obj.SendEmailAddress1, depth-1),
			"EmailAddress2": sysObjToStdObj(obj.EmailAddress2, depth-1),
			"SendEmailAddress2": sysObjToStdObj(obj.SendEmailAddress2, depth-1),
			"SchoolOther": sysObjToStdObj(obj.SchoolOther, depth-1),
			"Grade": sysObjToStdObj(obj.Grade, depth-1),
			"EmployerOther": sysObjToStdObj(obj.EmployerOther, depth-1),
			"OccupationOther": sysObjToStdObj(obj.OccupationOther, depth-1),
			"MaritalStatusId": sysObjToStdObj(obj.MaritalStatusId, depth-1),
			"WeddingDate": sysObjToStdObj(obj.WeddingDate, depth-1),
			"DOB": sysObjToStdObj(obj.DOB, depth-1),
			"DoNotCallFlag": sysObjToStdObj(obj.DoNotCallFlag, depth-1),
			"DoNotMailFlag": sysObjToStdObj(obj.DoNotMailFlag, depth-1),
			"DoNotVisitFlag": sysObjToStdObj(obj.DoNotVisitFlag, depth-1),
			"DoNotPublishPhones": sysObjToStdObj(obj.DoNotPublishPhones, depth-1),
			"ReceiveSMS": sysObjToStdObj(obj.ReceiveSMS, depth-1),
			"PositionInFamilyId": sysObjToStdObj(obj.PositionInFamilyId, depth-1),
			"CampusId": sysObjToStdObj(obj.CampusId, depth-1),
			"DeceasedDate": sysObjToStdObj(obj.DeceasedDate, depth-1),
			"MemberStatusId": sysObjToStdObj(obj.MemberStatusId, depth-1),
			"JoinDate": sysObjToStdObj(obj.JoinDate, depth-1),
			"DecisionTypeId": sysObjToStdObj(obj.DecisionTypeId, depth-1),
			"DecisionDate": sysObjToStdObj(obj.DecisionDate, depth-1),
			"BaptismTypeId": sysObjToStdObj(obj.BaptismTypeId, depth-1),
			"BaptismDate": sysObjToStdObj(obj.BaptismDate, depth-1),
			"BaptismSchedDate": sysObjToStdObj(obj.BaptismSchedDate, depth-1),
			"OtherPreviousChurch": sysObjToStdObj(obj.OtherPreviousChurch, depth-1),
			"JoinCodeId": sysObjToStdObj(obj.JoinCodeId, depth-1),
			"DropCodeId": sysObjToStdObj(obj.DropCodeId, depth-1),
			"DropDate": sysObjToStdObj(obj.DropDate, depth-1),
			"OtherNewChurch": sysObjToStdObj(obj.OtherNewChurch, depth-1),
			#"EmContact": obj.EmContact or None ,
			#"EmPhone": obj.EmPhone or None,
			#"MedicalDescription": obj.MedicalDescription or None,
			"NewMemberClassStatusId": sysObjToStdObj(obj.NewMemberClassStatusId, depth-1),
			"NewMemberClassDate": sysObjToStdObj(obj.NewMemberClassDate, depth-1),
			"MemberStatus": sysObjToStdObj(obj.MemberStatus, depth-1),
			"MemberStatusId": sysObjToStdObj(obj.MemberStatusId, depth-1),
			#"FamilyAddress": sysObjToStdObj(obj.FamilyAddress, depth-1),
			#"PersonalAddress": sysObjToStdObj(obj.PersonalAddress, depth-1),
			"AddressTypeId": sysObjToStdObj(obj.AddressTypeId, depth-1),
			"Usernames": sysObjToStdObj(obj.Users, depth-1),
			#"OrganizationMembers": sysObjToStdObj(obj.OrganizationMembers, depth)
		}
	else:
		if t.startswith("EntitySet[") or t.startswith("Array["):
			r = []
			for i in obj:
				r.append(sysObjToStdObj(i, depth))

		else:
			r = {"_class": t}
			if (depth > 0):
				for p in dir(obj):
					# don't import built-ins.
					if p.startswith("__"):
						continue

					# blacklist of parameters to exclude
					if p in ["People", "EnrollmentTransactions", "PaymentMethods", "PropertyChanging", "PropertyChanged", "PaymentInfos", "Attends", "Contributions", "TransactionPeople", "RecurringAmounts", "StmtPeople", "EnvPeople", "Tags", "TagsOwned"]:
					 	continue

					 # blacklist of types to exclude
					if type(getattr(obj, p)).__name__ in ["builtin_function_or_method", "EntitySet[EmailQueue]", "EntitySet[EmailQueueTo]", "EntitySet[EmailResponse]"]:
						continue

					r[p] = sysObjToStdObj(getattr(obj, p), depth-1)

			else:
				if hasattr(obj, "Id"):
					r["Id"] = sysObjToStdObj(getattr(obj, "Id"), depth-1)
				elif hasattr(obj, t + "Id"):
					r[t + "Id"] = sysObjToStdObj(getattr(obj, t + "Id"), depth-1)

	return r

def handleLogin():
	pid = 0
	if (hasattr(model, "UserPeopleId")):
		pid = model.UserPeopleId

	if pid < 1:
		model.Title = "Error"
		model.Header = "Something went wrong."
		print "<p>User not found.  If you receive this error message again, please email <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p"

	else:
		headers = {
			"X-API-KEY": model.Setting("wp_apiToken", ""),
			"content-type": "application/json"
			}

		p = model.GetPerson(pid)

		body = {
			"u": sysObjToStdObj(p, 2),
			"o": [],
			}

		if (Data.sessionToken is not ''):
			body["sessionToken"] = Data.sessionToken  # Note that this key is absent if no value is available.

		if (Data.r is not ''):
			body["linkedRequest"] = True

		#for om in p.OrganizationMembers:
		#	body['u']['OrganizationMembers'].append(sysObjToStdObj(om, 0))

		for oid in model.OrganizationIds(0, 0):
			if (model.InOrg(pid, oid)):
				body['o'].append(model.GetOrganization(oid))

		body = model.JsonSerialize(body)

		# TODO !important! check that redirect host matches the known hosts.  Otherwise, we shouldn't bother.

		response = model.RestPostJson(HOST + WP_LOGIN_PATH + "?action=touchpoint", headers, body)

		model.Title = "Login"
		model.Header = "Processing..."

		try:
			response = json.loads(response)

			if ("apiToken" in response) and (model.Setting("wp_apiToken", "") != response["apiToken"]):
				model.SetSetting("wp_apiToken", response["apiToken"])

			if ("wpid" in response) and (model.ExtraValueInt(pid, WP_USER_EV_KEY) != response["wpid"]):
				model.AddExtraValueInt(pid, WP_USER_EV_KEY, response["wpid"])

			redir = HOST + WP_LOGIN_PATH + '?loginToken=' + response["userLoginToken"]

			if (Data.redirect_to is not ''):
				redir += "&redirect_to=" + Data.redirect_to

			if (Data.r is not ''):
				redir += "&redirect_to=" + HOST + Data.r

			print("REDIRECT=" + redir)

		except:
			model.Title = "Error"
			model.Header = "Something went wrong."

			print "<p>Please email the following error message to <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p><pre>"
			pprint(response)
			print "</pre>"

if Data.action == "logout":
	redir = ""
	if (hasattr(Data, "redirect_to")):
		redir = Data.redirect_to

	model.Title = "Logging out..."
	model.Header = "Logging out..."
	model.Script = "<script>document.getElementById('logoutIFrame').onload = function() { window.location = \"" + redir + "\"; }</script>"
	print "<iframe id=\"logoutIFrame\" src=\"/Account/LogOff/\" style=\"position:absolute; top:-1000px; left:-10000px; width:2px; height:2px;\" ></iframe>"

elif Data.action == "login" or Data.r is not None:
	handleLogin()

else:
	model.Title = "Invalid Request"
	model.Header = "Invalid Request"
	print "<p>Something went wrong.  If it happens again, please email <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p>"