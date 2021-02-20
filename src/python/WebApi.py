#API

import re

PersonEvNames = {
	'geoLat': 'geoLat',
	'geoLng': 'geoLng'
}

if (Data.a == "Divisions"):
	divSql = '''SELECT d.id, CONCAT(p.name, ' : ', d.name) as name FROM Division d
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
	os1.schedTime as sched1Time,
	os1.schedDay as sched1Day,
	os1.nextMeetingDate as sched1NextMeeting,
	os2.schedTime as sched2Time,
	os2.schedDay as sched2Day,
	os2.nextMeetingDate as sched2NextMeeting,
	m.meetingDate as meetNextMeeting,
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
		) agg
	) as age_groups
	FROM Organizations o
	LEFT JOIN OrgSchedule AS os1 ON
		(o.OrganizationId = os1.OrganizationId AND
		os1.Id = 1)
	LEFT JOIN OrgSchedule AS os2 ON
		(o.OrganizationId = os2.OrganizationId AND
		os2.Id = 2)
	LEFT JOIN Meetings AS m ON
		(o.OrganizationId = m.OrganizationId AND
		m.meetingDate > getdate())
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

		if leadMemTypes != "":
			leaderSql = '''
			SELECT om.PeopleId AS id, p.FamilyId as familyId, p.LastName as lastName, COALESCE(p.NickName, p.FirstName) as goesBy
			FROM OrganizationMembers om JOIN People p ON om.PeopleId = p.PeopleId
			WHERE OrganizationId IN ({}) AND MemberTypeId IN ({})
			ORDER BY p.FamilyId'''.format(g.involvementId, leadMemTypes)

			g.leaders = model.SqlListDynamicData(leaderSql)
			
		if hostMemTypes != "":
			hostSql = '''
			SELECT TOP 1 peLat.Data as lat, peLng.Data as lng, COALESCE(prc.Description, frc.Description) as resCodeName
			FROM OrganizationMembers om
				JOIN People p ON om.PeopleId = p.PeopleId
				JOIN PeopleExtra as peLat ON peLat.PeopleId = p.PeopleId and peLat.Field = '{}'
				JOIN PeopleExtra as peLng ON peLng.PeopleId = p.PeopleId and peLng.Field = '{}'
				LEFT JOIN lookup.ResidentCode prc ON p.ResCodeId = prc.Id
				JOIN Families f on p.FamilyId = f.FamilyId
				LEFT JOIN lookup.ResidentCode frc ON f.ResCodeId = frc.Id
			WHERE OrganizationId IN ({}) AND MemberTypeId IN ({})'''.format(PersonEvNames['geoLat'], PersonEvNames['geoLng'], g.involvementId, hostMemTypes)

			g.hostGeo = model.SqlTop1DynamicData(hostSql) # TODO: merge into main query?

	Data.invs = groups



	# Get Extra Values in use on these involvements  TODO put somewhere useful
#	 invEvSql = '''SELECT DISTINCT [Field], [Type] FROM OrganizationExtra oe
#					  LEFT JOIN DivOrg do ON oe.OrganizationId = do.OrgId WHERE DivId IN ({})'''.format(divs)
#
#	 Data.invev = model.SqlListDynamicData(invEvSql) # TODO move to separate request

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