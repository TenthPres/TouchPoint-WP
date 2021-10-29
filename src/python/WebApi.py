#API

import re

VERSION = "0.0.4"

PersonEvNames = {
	'geoLat': 'geoLat',
	'geoLng': 'geoLng',
	'geoHsh': 'geoHsh'
}
sgContactEvName = "Contact"
defaultSgTaskDelegatePid = 16371

def getPersonInfoSql(tableAbbrev):
    return "SELECT DISTINCT {0}.PeopleId AS peopleId, {0}.FamilyId as familyId, {0}.LastName as lastName, COALESCE({0}.NickName, {0}.FirstName) as goesBy, SUBSTRING({0}.LastName, 1, 1) as lastInitial".format(tableAbbrev)

def getPersonSortSql(tableAbbrev):
    return " SUBSTRING({0}.LastName, 1, 1) ASC, COALESCE({0}.NickName, {0}.FirstName) ASC ".format(tableAbbrev)

if (Data.a == "Divisions"):
	divSql = '''
	SELECT d.id,
		CONCAT(p.name, ' : ', d.name) as name,
		p.name as pName,
		p.Id as proId,
		d.name as dName
	FROM Division d
	JOIN Program p on d.progId = p.Id
	ORDER BY p.name, d.name'''

	Data.title = "All Divisions"
	Data.divs = q.QuerySql(divSql, {})

elif (Data.a == "ResCodes"):
	rcSql = '''SELECT Id, Code, Description as Name FROM lookup.ResidentCode'''
	Data.title = "All Resident Codes"
	Data.resCodes = q.QuerySql(rcSql, {})

elif (Data.a == "Genders"):
	rcSql = '''SELECT Id, Code, Description as Name FROM lookup.Gender'''
	Data.title = "All Genders"
	Data.genders = q.QuerySql(rcSql, {})

elif (Data.a == "InvsForDivs"):
	regex = re.compile('[^0-9\,]')
	divs = regex.sub('', Data.divs)

	leadMemTypes = Data.leadMemTypes or ""
	leadMemTypes = regex.sub('', leadMemTypes)

	hostMemTypes = Data.hostMemTypes or ""
	hostMemTypes = regex.sub('', hostMemTypes)

	invSql = '''SELECT o.organizationId as involvementId,
	o.leaderMemberTypeId,
	o.location,
	o.organizationName as name,
	o.memberCount,
	o.classFilled as groupFull,
	o.genderId,
	o.description,
	o.registrationClosed as closed,
	o.notWeekly,
	o.registrationTypeId as regTypeId,
	o.orgPickList,
	o.mainLeaderId,
	o.RegSettingXml.exist('/Settings/AskItems') AS hasRegQuestions,
	FORMAT(o.RegStart, 'yyyy-MM-ddTHH:mm:ss') as regStart,
	FORMAT(o.RegEnd, 'yyyy-MM-ddTHH:mm:ss') as regEnd,
	FORMAT(o.FirstMeetingDate, 'yyyy-MM-ddTHH:mm:ss') as firstMeeting,
	FORMAT(o.LastMeetingDate, 'yyyy-MM-ddTHH:mm:ss') as lastMeeting,
	(SELECT COUNT(pi.MaritalStatusId) FROM OrganizationMembers omi
		LEFT JOIN People pi ON omi.PeopleId = pi.PeopleId AND omi.OrganizationId = o.organizationId AND pi.MaritalStatusId NOT IN (0)) as marital_denom,
	(SELECT COUNT(pi.MaritalStatusId) FROM OrganizationMembers omi
		LEFT JOIN People pi ON omi.PeopleId = pi.PeopleId AND omi.OrganizationId = o.organizationId AND pi.MaritalStatusId IN (20)) as marital_married,
	(SELECT COUNT(pi.MaritalStatusId) FROM OrganizationMembers omi
		LEFT JOIN People pi ON omi.PeopleId = pi.PeopleId AND omi.OrganizationId = o.organizationId AND pi.MaritalStatusId NOT IN (0, 20)) as marital_single,
	(SELECT STRING_AGG(ag, ',') WITHIN GROUP (ORDER BY ag ASC) FROM
		(SELECT DISTINCT (CASE
             WHEN pi.Age > 69 THEN '70+'
             ELSE CONVERT(VARCHAR(2), (FLOOR(pi.Age / 10.0) * 10), 70) + 's'
             END) as ag FROM OrganizationMembers omi
         		LEFT JOIN People pi ON omi.PeopleId = pi.PeopleId AND omi.OrganizationId = o.OrganizationId
         		WHERE pi.Age > 19
		) ag_agg
	) as age_groups,
	(SELECT STRING_AGG(sdt, ' | ') WITHIN GROUP (ORDER BY sdt ASC) FROM
		(SELECT CONCAT(FORMAT(NextMeetingDate, 'yyyy-MM-ddTHH:mm:ss'), '|S') as sdt FROM OrgSchedule os
			WHERE os.OrganizationId = o.OrganizationId
		UNION
		SELECT CONCAT(FORMAT(meetingDate, 'yyyy-MM-ddTHH:mm:ss'), '|M') as sdt FROM Meetings as m
			WHERE m.meetingDate > getdate() AND m.OrganizationId = o.OrganizationId
		) s_agg
	) as occurrences,
	(SELECT STRING_AGG(divId, ',') WITHIN GROUP (ORDER BY divId ASC) FROM
		(SELECT divId FROM DivOrg do
			WHERE do.OrgId = o.OrganizationId
			) d_agg
	) as divs
	FROM Organizations o
	WHERE o.OrganizationId = (
		SELECT MIN(OrgId)
		FROM DivOrg
		WHERE OrgId = o.OrganizationId
		AND DivId IN ({})
	)
	AND o.organizationStatusId = 30'''.format(divs)

	groups = model.SqlListDynamicData(invSql)

	for g in groups:
		if g.age_groups != None:
			g.age_groups = g.age_groups.split(',')

		if g.divs != None:
			g.divs = g.divs.split(',')

		if g.occurrences != None:
			g.occurrences = g.occurrences.split(' | ')
			uniqueOccurrences = []
			for i, s in enumerate(g.occurrences):
				if s[0:19] not in uniqueOccurrences: # filter out occurrences provided by both Meetings and Schedules
					uniqueOccurrences.append(s[0:19])
					g.occurrences[i] = {'dt': s[0:19], 'type': s[20:]}
				else:
					g.occurrences.remove(s)
		else:
			g.occurrences = []

		if leadMemTypes != "":
			leaderSql = '''
			SELECT om.PeopleId AS id, p.FamilyId as familyId, p.LastName as lastName, COALESCE(p.NickName, p.FirstName) as goesBy
			FROM OrganizationMembers om JOIN People p ON om.PeopleId = p.PeopleId
			WHERE OrganizationId IN ({}) AND MemberTypeId IN ({})
			ORDER BY p.FamilyId'''.format(g.involvementId, leadMemTypes)

			g.leaders = model.SqlListDynamicData(leaderSql)

		if hostMemTypes != "":
			hostSql = '''
			SELECT TOP 1 peLat.Data as lat, peLng.Data as lng, peHsh.Data as hsh, COALESCE(prc.Description, frc.Description) as resCodeName
			FROM OrganizationMembers om
				JOIN People p ON om.PeopleId = p.PeopleId
				JOIN PeopleExtra as peLat ON peLat.PeopleId = p.PeopleId and peLat.Field = '{}'
				JOIN PeopleExtra as peLng ON peLng.PeopleId = p.PeopleId and peLng.Field = '{}'
				JOIN PeopleExtra as peHsh ON peHsh.PeopleId = p.PeopleId and peHsh.Field = '{}'
				LEFT JOIN lookup.ResidentCode prc ON p.ResCodeId = prc.Id
				JOIN Families f on p.FamilyId = f.FamilyId
				LEFT JOIN lookup.ResidentCode frc ON f.ResCodeId = frc.Id
			WHERE OrganizationId IN ({}) AND MemberTypeId IN ({})'''.format(
			PersonEvNames['geoLat'],
			PersonEvNames['geoLng'],
			PersonEvNames['geoHsh'],
			g.involvementId,
			hostMemTypes)

			g.hostGeo = model.SqlTop1DynamicData(hostSql) # TODO: merge into main query?

	Data.invs = groups

	# Get Extra Values in use on these involvements  TODO put somewhere useful
	invEvSql = '''SELECT DISTINCT [Field], [Type] FROM OrganizationExtra oe
				  LEFT JOIN DivOrg do ON oe.OrganizationId = do.OrgId WHERE DivId IN ({})'''.format(divs)

	Data.invev = model.SqlListDynamicData(invEvSql) # TODO move to separate request

elif (Data.a == "MemTypes"):
	divs = Data.divs or ""

	regex = re.compile('[^0-9\,]')
	divs = regex.sub('', divs)

	memTypeSql = '''SELECT DISTINCT om.[MemberTypeId] as id, mt.[Code] as code, mt.[Description] as description FROM OrganizationMembers om
					JOIN DivOrg do ON om.OrganizationId = do.OrgId
					JOIN lookup.MemberType mt ON om.[MemberTypeId] = mt.[Id]'''
	if divs != "":
		memTypeSql += " WHERE do.DivId IN ({})".format(divs)
	memTypeSql += " ORDER BY description ASC"

	Data.memTypes = model.SqlListDynamicData(memTypeSql)


elif (Data.a == "ident"):  # This is a POST request. TODO possibly limit to post?
    Data.Title = 'Matching People'
    inData = model.JsonDeserialize(Data.data).inputData

    if inData.firstName is not None and inData.lastName is not None:
    	# more than email and zip

    	pid = model.FindAddPeopleId(inData.firstName, inData.lastName, inData.dob, inData.email, inData.phone)

    	sql = getPersonInfoSql('p2') + """
        			FROM People p1
        				JOIN Families f ON p1.FamilyId = f.FamilyId
        				JOIN People p2 ON p1.FamilyId = p2.FamilyId
        			WHERE p1.peopleId = {0}
        			ORDER BY""".format(pid) + getPersonSortSql('p2')
        Data.people = model.SqlListDynamicData(sql)

    else:
    	# email and zip only

		sql = getPersonInfoSql('p2') + """
			FROM People p1
				JOIN Families f ON p1.FamilyId = f.FamilyId
				JOIN People p2 ON p1.FamilyId = p2.FamilyId
			WHERE (p1.EmailAddress = '{0}' OR p1.EmailAddress2 = '{0}')
				AND (p1.ZipCode LIKE '{1}%' OR f.ZipCode LIKE '{1}%')
			ORDER BY""".format(inData.email, inData.zip) + getPersonSortSql('p2')
			# TODO add EV Email archive

		Data.people = model.SqlListDynamicData(sql)

elif (Data.a == "inv_join"):  # This is a POST request. TODO possibly limit to post?
    Data.Title = 'Adding people to Involvement'
    inData = model.JsonDeserialize(Data.data).inputData

    oid = inData.invId
    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName)
    orgContactPid = q.QuerySqlTop1(orgContactSql).contactId
    orgContactPid = orgContactPid if orgContactPid is not None else defaultSgTaskDelegatePid

    Data.success = []

    addPeople = []
    for p in inData.people:
        if not model.InOrg(p.peopleId, oid):
            model.AddMemberToOrg(p.peopleId, oid)
            model.SetMemberType(p.peopleId, oid, "Prospect")
            addPeople.append(model.GetPerson(p.peopleId))
        Data.success.append({'pid': p.peopleId, 'invId': oid, 'cpid': orgContactPid})

    if len(addPeople) > 0:
        org = model.GetOrganization(oid)
        names = " & ".join(p.FirstName for p in addPeople)  # TODO develop a better name listing mechanism for python.
        pidStr = "(P" + ") (P".join(str(p.PeopleId) for p in addPeople) + ")"
        model.CreateTaskNote(defaultSgTaskDelegatePid,
            addPeople[0].PeopleId,
            orgContactPid,
            None,
            False,
            """**{0} {2} interested in joining {1}**. Please reach out to welcome them and mark the task as complete.

They have also been added to your roster as prospective members.  Please move them to being a member of the group when appropriate.

{3}
""".format(names, org.name, "is" if len(addPeople) == 1 else "are", pidStr),
	    	None,
	    	None,
	    	[2, 4, 5])  # keyword IDs

elif (Data.a == "inv_contact"):  # This is a POST request. TODO possibly limit to post?
	# TODO potentially merge with Join function.  Much of the code is duplicated.
    Data.Title = 'Contacting Involvement Leaders'
    inData = model.JsonDeserialize(Data.data).inputData

    oid = inData.invId
    message = inData.message
    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName)
    orgContactPid = q.QuerySqlTop1(orgContactSql).contactId
    orgContactPid = orgContactPid if orgContactPid is not None else defaultSgTaskDelegatePid

    Data.success = []

    p = inData.fromPerson
    m = inData.message
    org = model.GetOrganization(oid)

    model.CreateTaskNote(defaultSgTaskDelegatePid, p.peopleId, orgContactPid, None, False,
    """**Online Contact Form: {0}**

{1} sent the following message.  Please reach out to them and mark the task as complete.

    {2}""".format(org.name, p.goesBy, str(m).replace("\n", "\n    ")),  # being indented causes section to be treated like code
    None, None, [2, 4, 6])

    Data.success.append({'pid': p.peopleId, 'invId': oid, 'cpid': orgContactPid})


elif (Data.a == "mtg"):  # This is a POST request. TODO possibly limit to post?
    Data.Title = 'Getting Meeting Info'
    inData = model.JsonDeserialize(Data.data).inputData

    Data.success = q.QuerySql(
    '''
    SELECT 	m.meetingId as mtgId,
    		m.organizationId as invId,
    		m.location,
    		FORMAT(m.meetingDate, 'yyyy-MM-ddTHH:mm:ss') as mtgDate,
    		m.description,
    		m.capacity,
     	   	o.organizationName as invName
    FROM Meetings m LEFT JOIN Organizations o ON m.organizationId = o.organizationId
    WHERE MeetingId IN ({})
    '''.format(inData.mtgRefs)
    )

elif (Data.a == "mtg_rsvp"):  # This is a POST request. TODO possibly limit to post?
    Data.Title = 'Recording RSVPs'
    inData = model.JsonDeserialize(Data.data).inputData

    mid = inData.mtgId
    Data.success = []
    if inData.responses.Yes is not None:
		for pid in inData.responses.Yes:
			model.EditCommitment(mid, pid, "Attending")
			Data.success.append(pid)

    if inData.responses.No is not None:
		for pid in inData.responses.No:
			model.EditCommitment(mid, pid, "Regrets")
			Data.success.append(pid)

#