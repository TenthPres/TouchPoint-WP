from pprint import pprint
import json
import sys

HOST = model.Setting("wp_host")
USER_EV_KEY = "WordPress User ID"

if hasattr(Data, "action"):
    if Data.action == "logout":
        redir = ""
        if (hasattr(Data, "redirect_to")):
            redir = Data.redirect_to

        model.Title = "Logging out..."
        model.Header = "Logging out..."
        model.Script = "<script>document.getElementById('logoutIFrame').onload = function() { window.location = \"" + redir + "\"; }</script>"
        print "<iframe id=\"logoutIFrame\" src=\"/Account/LogOff/\" style=\"position:absolute; top:-1000px; left:-10000px; width:2px; height:2px;\" ></iframe>"

    elif Data.action == "login":

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

			body = {
				"p": {
					"obj": model.GetPerson(pid),
					"wpid": model.ExtraValueInt(pid, WP_USER_EV_KEY)
					},
				"o": [],
				}

			if (hasattr(Data, "sessionToken")):
				body["sessionToken"] = Data.sessionToken  # Note that this key is absent if no value is available.

			for oid in model.OrganizationIds(0, 0):
				if (model.InOrg(pid, oid)):
					body['o'].append(model.GetOrganization(oid))

			# TODO add church membership

			body = model.JsonSerialize(body)

			response = model.RestPostJson(HOST + "?action=touchpoint", headers, body)

			model.Title = "Login"
			model.Header = "Processing..."

			try:
				response = json.loads(response)

				if ("apiToken" in response) and (model.Setting("wp_apiToken", "") != response["apiToken"]):
					model.SetSetting("wp_apiToken", response["apiToken"])

				if ("wpid" in response) and (model.ExtraValueInt(pid, WP_USER_EV_KEY) != response["wpid"]:
					model.AddExtraValueInt(pid, WP_USER_EV_KEY, response["wpid"])

				redir = HOST + '?loginToken=' + response["userLoginToken"]

				if (hasattr(Data, "redirect_to")):
					redir += "&redirect_to=" + Data.redirect_to

				print("REDIRECT=" + redir)

			except:
				model.Title = "Error"
				model.Header = "Something went wrong."

				print "<p>Please email the following error message to <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p><pre>"
				pprint(response)
				print "</pre>"

    else:
        model.Title = "Invalid Request"
        model.Header = "Invalid Request"
        print "<p>Something went wrong.  If it happens again, please email <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p>"