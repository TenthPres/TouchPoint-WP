#API

import re
import json

VERSION = "0.0.9"

sgContactEvName = "Contact"

def getPersonInfoSql(tableAbbrev):
    return "SELECT DISTINCT {0}.PeopleId AS peopleId, {0}.FamilyId as familyId, {0}.LastName as lastName, COALESCE({0}.NickName, {0}.FirstName) as goesBy, SUBSTRING({0}.LastName, 1, 1) as lastInitial".format(tableAbbrev)

def getPersonSortSql(tableAbbrev):
    return " SUBSTRING({0}.LastName, 1, 1) ASC, COALESCE({0}.NickName, {0}.FirstName) ASC ".format(tableAbbrev)

def getPersonInfoForSync(PersonObj):
    p = model.DynamicData()
    p.Exclude = False
    p.LastName = PersonObj.LastName
    p.GoesBy = PersonObj.NickName if PersonObj.NickName is not None else PersonObj.FirstName
    p.DisplayName = " "
    p.DecoupleLocation = False

    # Person's Picture
    if PersonObj.Picture is None:
        p.Picture = None
    else:
        p.Picture = {
            'large': PersonObj.Picture.LargeUrl,
            'medium': PersonObj.Picture.MediumUrl,
            'small': PersonObj.Picture.SmallUrl,
            'thumb': PersonObj.Picture.ThumbUrl,
            'x': PersonObj.Picture.X,
            'y': PersonObj.Picture.Y
        }

    # Email addresses
    p.Emails = []
    if PersonObj.EmailAddress is not None:
        p.Emails.append(PersonObj.EmailAddress)
    if PersonObj.EmailAddress2 is not None:
        p.Emails.append(PersonObj.EmailAddress2)

    # Send to WebPublicPerson script
    model.Data.Person = PersonObj
    model.Data.Info = p
    model.CallScript('WebPublicPerson')
    p = model.Data.Info
    model.Data.Person = None
    model.Data.Info = None

    # Standardizing returns from WebPublicPerson
    if p.Exclude is True:
        return None

    if p.DisplayName == " ": # default DisplayName
        p.DisplayName = (p.GoesBy + " " + p.LastName).strip()

    p.Usernames = map(lambda u: u.Username, PersonObj.Users)

    p.PeopleId = PersonObj.PeopleId
    p.FamilyId = PersonObj.FamilyId
    p.GenderId = PersonObj.GenderId
    return p

Data.a = Data.a.split(',')

if ("Divisions" in Data.a):
    divSql = '''
    SELECT d.id,
        CONCAT(p.name, ' : ', d.name) as name,
        p.name as pName,
        p.Id as proId,
        d.name as dName
    FROM Division d
    JOIN Program p on d.progId = p.Id
    ORDER BY p.name, d.name'''

    Data.Title = "All Divisions"
    Data.divs = q.QuerySql(divSql, {})

if ("ResCodes" in Data.a):
    rcSql = '''SELECT Id, Code, Description as Name FROM lookup.ResidentCode'''
    Data.Title = "All Resident Codes"
    Data.resCodes = q.QuerySql(rcSql, {})

if ("Genders" in Data.a):
    rcSql = '''SELECT Id, Code, Description as Name FROM lookup.Gender'''
    Data.Title = "All Genders"
    Data.genders = q.QuerySql(rcSql, {})

if ("Keywords" in Data.a):
    kwSql = '''SELECT KeywordId as Id, Code, Description as Name FROM Keyword ORDER BY Code'''
    Data.Title = "All Keywords"
    Data.keywords = q.QuerySql(kwSql, {})

if ("PersonEvFields" in Data.a):
    pevSql = '''SELECT Field, [Type], count(*) as Count,
                CONCAT('pev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
                FROM PeopleExtra WHERE [Field] NOT LIKE '%_mv'
                GROUP BY [Field], [Type] ORDER BY count(*) DESC'''
    Data.Title = "Person Extra Value Fields"
    Data.personEvFields = q.QuerySql(pevSql, {})

if ("FamilyEvFields" in Data.a):
    fevSql = '''SELECT Field, [Type], count(*) as Count,
                CONCAT('fev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
                FROM FamilyExtra WHERE [Field] NOT LIKE '%_mv'
                GROUP BY [Field], [Type] ORDER BY count(*) DESC'''
    Data.Title = "Family Extra Value Fields"
    Data.familyEvFields = q.QuerySql(fevSql, {})

if ("SavedSearches" in Data.a):
    Data.savedSearches = model.DynamicData()

    if Data.PeopleId == '':
        Data.savedSearches.public = model.SqlListDynamicData("""
            SELECT TOP 100 q.Name, q.QueryId FROM Query q JOIN Users u ON LOWER(q.Owner) = LOWER(u.Username)
            WHERE q.IsPublic = 1 AND q.LastRun > DATEADD(DAY, -90, GETDATE()) AND q.Name <> 'Draft'
            ORDER BY q.Name
        """)
    else:
        Data.savedSearches.user = model.SqlListDynamicData("""
            SELECT TOP 100 q.Name, q.QueryId FROM Query q JOIN Users u ON LOWER(q.Owner) = LOWER(u.Username)
            WHERE (u.PeopleId = {0}) AND q.LastRun > DATEADD(DAY, -90, GETDATE()) AND q.Name <> 'Draft'
            ORDER BY q.Name
        """.format(Data.PeopleId))
        Data.savedSearches.public = model.SqlListDynamicData("""
            SELECT TOP 100 q.Name, q.QueryId FROM Query q JOIN Users u ON LOWER(q.Owner) = LOWER(u.Username)
            WHERE (q.IsPublic = 1 AND u.PeopleId <> {0}) AND q.LastRun > DATEADD(DAY, -90, GETDATE()) AND q.Name <> 'Draft'
            ORDER BY q.Name
        """.format(Data.PeopleId))

    Data.savedSearches.flags = model.SqlListDynamicData("""
        SELECT TOP 100 q.Name, SUBSTRING(q.Name, 0, 4) AS QueryId FROM Query q
        WHERE q.Name LIKE 'F[0-9][0-9]:%'
        ORDER BY q.Name
    """)

    Data.Title = "Saved Searches"

if ("InvsForDivs" in Data.a):
    regex = re.compile('[^0-9\,]')
    divs = regex.sub('', Data.divs)

    leadMemTypes = Data.leadMemTypes or ""
    leadMemTypes = regex.sub('', leadMemTypes)

    hostMemTypes = Data.hostMemTypes or ""
    hostMemTypes = regex.sub('', hostMemTypes)

    if hostMemTypes == "":
        hostMemTypes = "NULL"

    invSql = '''SELECT
        o.organizationId as involvementId,
        o.leaderMemberTypeId,
        o.location,
        o.organizationName as name,
        o.memberCount,
        o.parentOrgId as parentInvId,
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
        ) as divs,
        COALESCE(oai.Latitude, paih.Latitude, faih.Latitude) lat,
        COALESCE(oai.Longitude, paih.Longitude, faih.Longitude) lng,
        COALESCE(orc.Description, prch.Description, frch.Description) resCodeName
        FROM Organizations o
            LEFT JOIN AddressInfo oai ON o.OrganizationId = oai.OrganizationId
            LEFT JOIN Zips z ON CAST(SUBSTRING(SUBSTRING(aoe.Data, 8, 1000), PATINDEX('%[0-9][0-9][0-9][0-9][0-9]%', SUBSTRING(aoe.Data, 8, 1000)), 5) as INT) = z.ZipCode
            LEFT JOIN lookup.ResidentCode orc ON z.MetroMarginalCode = orc.id
            LEFT JOIN People ph ON (SELECT TOP 1 omh.PeopleId FROM OrganizationMembers omh WHERE o.OrganizationId = omh.OrganizationId AND omh.MemberTypeId IN ({})) = ph.PeopleId
            LEFT JOIN Families fh ON ph.FamilyId = fh.FamilyId
            LEFT JOIN AddressInfo paih ON ph.PeopleId = paih.PeopleId
            LEFT JOIN AddressInfo faih ON fh.FamilyId = faih.FamilyId
            LEFT JOIN lookup.ResidentCode prch ON ph.ResCodeId = prch.Id
            LEFT JOIN lookup.ResidentCode frch ON fh.ResCodeId = frch.Id
        WHERE o.OrganizationId = (
            SELECT MIN(OrgId)
            FROM DivOrg
            WHERE OrgId = o.OrganizationId
            AND DivId IN ({})
        )
        AND o.organizationStatusId = 30
        ORDER BY o.ParentOrgId ASC, o.OrganizationId ASC'''.format(hostMemTypes, divs)

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
            SELECT om.PeopleId, p.FamilyId, p.LastName, COALESCE(p.NickName, p.FirstName) as GoesBy, p.GenderId
            FROM OrganizationMembers om JOIN People p ON om.PeopleId = p.PeopleId
            WHERE OrganizationId IN ({}) AND MemberTypeId IN ({})
            ORDER BY p.FamilyId'''.format(g.involvementId, leadMemTypes)

            g.leaders = model.SqlListDynamicData(leaderSql)

    Data.invs = groups

    # Get Extra Values in use on these involvements  TODO put somewhere useful
    invEvSql = '''SELECT DISTINCT [Field], [Type] FROM OrganizationExtra oe
                  LEFT JOIN DivOrg do ON oe.OrganizationId = do.OrgId WHERE DivId IN ({})'''.format(divs)

    Data.invev = model.SqlListDynamicData(invEvSql) # TODO move to separate request

if ("MemTypes" in Data.a):
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

if ("src" in Data.a and Data.q is not None):
    if len(Data.q) < 1:
        Data.people = []

    # Numeric query
    elif Data.q.isnumeric():
        sql = """SELECT TOP 10
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName) GoesBy,
                 p1.LastName,
                 SUM(score),
                 -- logins + attendance
                 (SELECT COUNT(*) FROM Attend a
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) FROM ActivityLog al
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
             FROM (
                 SELECT p.*, 10 as score FROM People p WHERE CAST(p.PeopleId as CHAR) = '{0}'
                 UNION
                 SELECT p.*, 6 as score FROM People p WHERE CAST(p.PeopleId as CHAR) LIKE '{0}%'
                 UNION
                 SELECT p.*, 3 as score FROM People p WHERE CAST(p.CellPhone as CHAR) LIKE '{0}%'
                 UNION
                 SELECT p.*, 3 as score FROM People p WHERE CAST(p.WorkPhone as CHAR) LIKE '{0}%'
             ) p1
             GROUP BY
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName),
                 p1.LastName
             ORDER BY SUM(score) DESC, partic DESC
             """.format(Data.q)

    # Single word
    elif Data.q.find(' ') == -1 and Data.q.find(',') == -1:
        sql = """SELECT TOP 10
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName) GoesBy,
                 p1.LastName,
                 SUM(score),
                 -- logins + attendance
                 (SELECT COUNT(*) FROM Attend a 
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) FROM ActivityLog al 
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
             FROM (
                 SELECT p.*, 10 as score FROM Users u JOIN People p ON u.PeopleId = p.PeopleId WHERE u.Username LIKE '{0}%'
                 UNION
                 SELECT p.*, 9 as score FROM People p WHERE p.EmailAddress LIKE '{0}%' OR p.EmailAddress2 LIKE '{0}%'
                 UNION
                 SELECT p.*, 8 as score FROM People p WHERE p.LastName LIKE '{0}%'
                 UNION
                 SELECT p.*, 7 as score FROM People p WHERE p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%'
                 UNION
                 SELECT p.*, 5 as score FROM People p WHERE p.AltName LIKE '{0}%' OR p.MaidenName LIKE '{0}%'
             ) p1
             GROUP BY 
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName),
                 p1.LastName
             ORDER BY SUM(score) DESC, partic DESC
             """.format(Data.q)

    # Multiple words
    else:
        if Data.q.find(',') > -1:
            [second, first] = Data.q.split(',', 1)
        else:
            [first, second] = Data.q.split(' ', 1)

        sql = """SELECT TOP 10
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName) GoesBy,
                 p1.LastName,
                 SUM(score),
                 -- logins + attendance
                 (SELECT COUNT(*) FROM Attend a
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) FROM ActivityLog al
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
             FROM (
                 SELECT p.*, 10 as score FROM People p WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') AND p.LastName LIKE '{1}%'
                 UNION
                 SELECT p.*, 8 as score FROM People p WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') AND (p.AltName LIKE '{1}%' OR p.MaidenName LIKE '{1}%')
                 UNION
                 SELECT p.*, 5 as score FROM People p WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') OR p.LastName LIKE '{1}%'
                 UNION
                 SELECT p.*, 4 as score FROM People p WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') OR (p.AltName LIKE '{1}%' OR p.MaidenName LIKE '{1}')
             ) p1
             GROUP BY
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName),
                 p1.LastName
             ORDER BY SUM(score) DESC, partic DESC
             """.format(first, second)

    Data.people = q.QuerySql(sql)


# Start POST requests

if Data.data != "":
    inData = json.loads(Data.data)['inputData']
else:
    inData = {}


if ("updateScripts" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'Updating Scripts'
    Data.scriptsUpdated = 0
    for filename, content in inData.items():
        model.WriteContentPython(filename, content, "Web")
        Data.scriptsUpdated = Data.scriptsUpdated + 1
    Data.data = None

if ("ident" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'Matching People'

    if inData.has_key('firstName') and inData.has_key('lastName') and inData['firstName'] is not None and inData['firstName'] is not None:
        # more than email and zip
        
        # coalescing.
        dob = None
        if inData.has_key('dob'):
            dob = inData['dob']
        
        email = None
        if inData.has_key('email'):
            email = inData['email']
        
        phone = None
        if inData.has_key('phone'):
            phone = inData['phone']

        pid = model.FindAddPeopleId(inData['firstName'], inData['lastName'], dob, email, phone)
        updates = {}
        p = model.GetPerson(pid)

        # Update Zip code.  Assumes US Zip codes for comparison
        if inData.has_key('zip') and inData['zip'] is not None and len(inData['zip']) > 4:
            if p.Family.ZipCode is not None and len(p.Family.ZipCode) > 0 and "{}".format(p.Family.ZipCode[0:5]) == "{}".format(inData['zip'][0:5]):
                pass # Family Address already has zip code
            elif p.ZipCode is not None and len(p.ZipCode) > 0 and "{}".format(p.ZipCode[0:5]) == "{}".format(inData['zip'][0:5]):
                pass # Person Address already has zip code
            else:
                updates['ZipCode'] = "{}".format(inData['zip'])
                updates['AddressLineOne'] = ""
                updates['AddressLineTwo'] = ""
                updates['CityName'] = ""
                updates['StateCode'] = ""

        # Update Phone
        if inData.has_key('phone') and inData['phone'] is not None and len(inData['phone']) > 9:
            cleanPhone = re.sub('[^0-9]', '', inData['phone'])
            if (p.HomePhone == cleanPhone or
                p.CellPhone == cleanPhone or
                p.WorkPhone == cleanPhone):
                pass # Phone already exists somewhere
            else:
                updates['CellPhone'] = cleanPhone

        # Update Email
        if inData.has_key('email') and inData['email'] is not None and len(inData['email']) > 5:
            if (p.EmailAddress.lower() == inData['email'].lower() or
                p.EmailAddress2.lower() == inData['email'].lower()):
                pass # Email already exists somewhere
            elif p.EmailAddress is None or p.EmailAddress == "" or p.SendEmailAddress1 == False:
                updates['EmailAddress'] = "{}".format(inData['email'])
                updates['SendEmailAddress1'] = True
            else:
                updates['EmailAddress2'] = "{}".format(inData['email'])
                updates['SendEmailAddress2'] = True

        # Submit the Updates
        if updates != {}:
            model.UpdatePerson(pid, updates)

        inData['fid'] = [p.FamilyId]

    else:
        # email and zip only

        sql = """SELECT DISTINCT p1.FamilyId
            FROM People p1
                JOIN Families f ON p1.FamilyId = f.FamilyId
            WHERE (p1.EmailAddress = '{0}' OR p1.EmailAddress2 = '{0}')
                AND (p1.ZipCode LIKE '{1}%' OR f.ZipCode LIKE '{1}%')""".format(inData['email'], inData['zip'])
            # TODO add EV Email archive

        inData['fid'] = q.QuerySqlInts(sql)

    Data.primaryFam = inData['fid']
    degreesOfSep = int(model.Setting("RegisterRelatedFamilies", "0"))

    if degreesOfSep > 1 and len(inData['fid']) > 0:
        sql = """SELECT DISTINCT rf1.fid FROM (
            SELECT rf1a.FamilyId fid, rf1a.RelatedFamilyId rid FROM RelatedFamilies rf1a UNION
            SELECT rf1b.RelatedFamilyId fid, rf1b.FamilyId rid FROM RelatedFamilies rf1b UNION
            SELECT rf1c.RelatedFamilyId fid, rf1c.RelatedFamilyId rid FROM RelatedFamilies rf1c UNION
            SELECT rf1d.FamilyId fid, rf1d.FamilyId rid FROM RelatedFamilies rf1d
        ) rf1 JOIN
        (
            SELECT rf2a.FamilyId fid, rf2a.RelatedFamilyId rid FROM RelatedFamilies rf2a UNION
            SELECT rf2b.RelatedFamilyId fid, rf2b.FamilyId rid FROM RelatedFamilies rf2b
        ) rf2 ON (rf1.rid = rf2.fid)
        WHERE rf2.rid IN ({})""".format(",".join(map(str, inData['fid'])))

        inData['fid'] = q.QuerySqlInts(sql)
        
    elif degreesOfSep == 1 and len(inData['fid']) > 0:
        sql = """SELECT DISTINCT rf1.fid FROM (
            SELECT rf1a.FamilyId fid, rf1a.RelatedFamilyId rid FROM RelatedFamilies rf1a UNION
            SELECT rf1b.RelatedFamilyId fid, rf1b.FamilyId rid FROM RelatedFamilies rf1b UNION
            SELECT rf1c.RelatedFamilyId fid, rf1c.RelatedFamilyId rid FROM RelatedFamilies rf1c UNION
            SELECT rf1d.FamilyId fid, rf1d.FamilyId rid FROM RelatedFamilies rf1d
        ) rf1
        WHERE rf1.rid IN ({})""".format(",".join(map(str, inData['fid'])))

        inData['fid'] = q.QuerySqlInts(sql)

    for f in Data.primaryFam:
        if f not in inData['fid']:
            inData['fid'].Add(f)

    if len(inData['fid']) > 0:
        Data.a.append("people_get")

if ("inv_join" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'Adding people to Involvement'

    oid = inData['invId']
    keywords = inData['keywords']
    owner = inData['owner']
    if not owner.isnumeric():
        owner = 1
    else:
        owner = int(owner)

    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName)
    orgContactPid = q.QuerySqlTop1(orgContactSql).contactId
    orgContactPid = orgContactPid if orgContactPid is not None else owner

    Data.success = []

    addPeople = []
    for p in inData['people']:
        if not model.InOrg(p['peopleId'], oid):
            model.AddMemberToOrg(p['peopleId'], oid)
            model.SetMemberType(p['peopleId'], oid, "Prospect")
            addPeople.append(model.GetPerson(p['peopleId']))
        Data.success.append({'pid': p['peopleId'], 'invId': oid, 'cpid': orgContactPid})

    if len(addPeople) > 0:
        org = model.GetOrganization(oid)
        names = " & ".join(p.FirstName for p in addPeople)  # TODO develop a better name listing mechanism for python.
        pidStr = "(P" + ") (P".join(str(p.PeopleId) for p in addPeople) + ")"

        text = """**{0} {2} interested in joining {1}**. Please reach out to welcome them and mark the task as complete.
They have also been added to your roster as prospective members.  Please move them to being a member of the group when appropriate.

{3}""".format(names, org.name, "is" if len(addPeople) == 1 else "are", pidStr)

        model.CreateTaskNote(owner, addPeople[0].PeopleId, orgContactPid,
            None, False, text, None, None, keywords)

if ("inv_contact" in Data.a and model.HttpMethod == "post"):
    # TODO potentially merge with Join function.  Much of the code is duplicated.
    Data.Title = 'Contacting Involvement Leaders'

    oid = inData['invId']
    message = inData['message']
    keywords = inData['keywords']
    owner = inData['owner']
    if not owner.isnumeric():
        owner = 1
    else:
        owner = int(owner)
    
    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName)
    orgContactPid = q.QuerySqlTop1(orgContactSql).contactId
    orgContactPid = orgContactPid if orgContactPid is not None else owner

    Data.success = []

    p = inData['fromPerson']
    m = inData['message']
    org = model.GetOrganization(oid)
    text = """**Online Contact Form: {0}**

{1} sent the following message.  Please reach out to them and mark the task as complete.

    {2}""".format(org.name, p['goesBy'], str(m).replace("\n", "\n    "))  # being indented causes section to be treated like code

    model.CreateTaskNote(owner, p['peopleId'], orgContactPid, None, False, text, None, None, keywords)

    Data.success.append({'pid': p['peopleId'], 'invId': oid, 'cpid': orgContactPid})


if ("person_wpIds" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'Updating WordPress IDs.'
    Data.success = 0

    ev = str(inData['evName'])

    for p in inData['people']:
        model.AddExtraValueInt(int(p['peopleId']), ev, int(p['WpId']))
        Data.success += 1


if ("person_contact" in Data.a and model.HttpMethod == "post"):
    # TODO potentially merge with Join function.  Much of the code is duplicated.
    Data.Title = 'Contacting Person'
    inData = model.JsonDeserialize(Data.data).inputData

    t = inData.toId
    message = inData.message
    keywords = inData.keywords

    Data.success = []

    p = inData.fromPerson
    m = inData.message
    text = """**Online Contact Form**

{0} sent the following message.  Please reach out to them and mark the task as complete.

    {1}""".format(p.goesBy, str(m).replace("\n", "\n    "))  # being indented causes section to be treated like code

    model.CreateTaskNote(t, p.peopleId, None, None, False, text, None, None, keywords)

    Data.success.append({'pid': p.peopleId, 'to': t})


if ("mtg" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'Getting Meeting Info'
    inData = model.JsonDeserialize(Data.data).inputData

    Data.success = []
    for mtg in q.QuerySql(
    '''
    SELECT  m.meetingId as mtgId,
            m.organizationId as invId,
            m.location,
            FORMAT(m.meetingDate, 'yyyy-MM-ddTHH:mm:ss') as mtgDate,
            COALESCE(m.description, '') as description,
            m.capacity,
            o.organizationName as invName
    FROM Meetings m LEFT JOIN Organizations o ON m.organizationId = o.organizationId
    WHERE MeetingId IN ({})
    '''.format(inData.mtgRefs)
    ):
        mtg.description = mtg.description.strip()
        if mtg.description == "":
            mtg.description = None
        if mtg.location is not None:
            mtg.location = mtg.location.strip()
        mtg.invName = mtg.invName.strip()
        Data.success.append(mtg)


if ("mtg_rsvp" in Data.a and model.HttpMethod == "post"):
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


if ("people_get" in Data.a and model.HttpMethod == "post"):
    Data.Title = 'People Query'

    rules = []
    invsMembershipsToImport = []
    invsMemSubGroupsToImport = {}
    joiner = "OR"
    if inData.has_key('groupBy'):
        sort = str(inData['groupBy']) # hypothetically should help speed grouping
    else:
        sort = ""

    # People Ids
    if inData.has_key('pid'):
        for pid in inData['pid']:
            rules.append("PeopleId = {}".format(pid))

    # Family Ids
    if inData.has_key('fid'):
        for fid in inData['fid']:
            rules.append("FamilyId = {}".format(fid))

    # Involvements
    if inData.has_key('inv'):
        for iid in inData['inv']:
            if inData['inv'][iid]['memTypes'] == None:
                rules.append("IsMemberOf( Org={} ) = 1".format(iid))
    
            if not iid in invsMembershipsToImport:
                invsMembershipsToImport.append(iid)
    
                # if inData['inv'][iid]['with_memTypes'] == True:
                #    invsMemSubGroupsToImport[iid] = inData['inv'][iid]['memTypes']

    # Saved Searches (incl status flags)
    if inData.has_key('inv'):
        for si in inData['src']:
            if len(si) == 3 and si[0].upper() == "F" and si[1:3].isnumeric(): # status flag
                rules.append("StatusFlag = '{}'".format(si))
            elif re.match('[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89AB][0-9a-f]{3}-[0-9a-f]{12}', si, re.I):
                rules.append("SavedQuery(SavedQuery='{}') = 1".format(si))

    joiner = " " + joiner + " "
    rules = joiner.join(rules)

    if not inData.has_key('groupBy') or inData['groupBy'] is None:
        outPeople = []
    else:
        outPeople = {}

    # Prep SQL for People Extra Values
    pevSql = ''
    if inData.has_key('meta') and isinstance(inData['meta'], dict) and inData['meta'].has_key('pev'):
        pevSql = []
        for pev in inData['meta']['pev']:
            pevSql.append("([Field] = '{}' AND [Type] = '{}')".format(pev['field'], pev['type']))
        pevSql = """SELECT Field, StrValue, DateValue, Data, IntValue, BitValue, [Type],
            CONCAT('pev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
            FROM PeopleExtra
            WHERE PeopleId = {} AND (""" + " OR ".join(pevSql) + ")"

    fevSql = ''
    useFamGeo = False
    if inData.has_key('meta') and isinstance(inData['meta'], dict) and inData['meta'].has_key('fev') and inData['groupBy'] == "FamilyId":
        useFamGeo = isinstance(inData['meta'], dict) and inData['meta'].has_key('geo')
        fevSql = []
        for fev in inData['meta']['fev']:
            fevSql.append("([Field] = '{}' AND [Type] = '{}')".format(fev['field'], fev['type']))
        if len(fevSql) > 0:
            fevSql = """SELECT Field, StrValue, DateValue, Data, IntValue, BitValue, [Type],
                CONCAT('fev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
                FROM FamilyExtra
                WHERE FamilyId = {} AND (""" + " OR ".join(fevSql) + ")"
        else:
            fevSql = ''

    invSql = "SELECT om.OrganizationId iid, CONCAT('mt', mt.Id) memType, CONCAT('at', at.Id) attType, om.UserData descr FROM OrganizationMembers om LEFT JOIN lookup.MemberType mt on om.MemberTypeId = mt.Id LEFT JOIN lookup.AttendType at ON mt.AttendanceTypeId = at.Id WHERE om.Pending = 0 AND mt.Inactive = 0 AND at.Guest = 0 AND om.PeopleId = {0} AND om.OrganizationId IN ({1})"

    famGeoSql = """SELECT geo.Longitude, geo.Latitude FROM AddressInfo ai LEFT JOIN Geocodes geo ON ai.FullAddress = geo.Address WHERE ai.FamilyId = {}"""

    Data.Context = inData['context']

    for po in q.QueryList(rules, sort.lower()):
        pr = getPersonInfoForSync(po)

        if pr is None: # Make sure person should not be excluded
            continue
        if pr.Exclude is True:  # Make sure person should not be excluded if PersonInfo didn't go right.
            continue

        if len(invsMembershipsToImport) > 0:
            pr.Inv = q.QuerySql(invSql.format(pr.PeopleId, ', '.join(invsMembershipsToImport)))

        # Make People Extra Values orderly
        if pevSql != '':
            pr.PeopleEV = {}
            for pev in q.QuerySql(pevSql.format(pr.PeopleId)):
                if pev.Type == 'Int':
                    pev.Data = pev.IntValue
                elif pev.Type == 'Bit':
                    pev.Data = pev.BitValue
                elif pev.Type == 'Date':
                    pev.Data = pev.DateValue
                pr.PeopleEV[pev.Hash] = {
                    'field': pev.Field,
                    'type': pev.Type,
                    'value': pev.Data
                }

        if not inData.has_key('groupBy') or inData['groupBy'] is None:
            outPeople.append(pr)
        else:
            grpId = getattr(po, inData['groupBy'])
            if not outPeople.has_key(grpId):  # group key does not yet exist.

                if fevSql != '':  # If grouped by family and we have Family EVs to return
                    fevOut = {}
                    for fev in q.QuerySql(fevSql.format(po.FamilyId)):
                        if fev.Type == 'Int':
                            fev.Data = fev.IntValue
                        elif fev.Type == 'Bit':
                            fev.Data = fev.BitValue
                        elif fev.Type == 'Date':
                            fev.Data = fev.DateValue
                        elif fev.Type == 'Code':
                            fev.Data = fev.StrValue
                        fevOut[fev.Hash] = {
                            'field': fev.Field,
                            'type': fev.Type,
                            'value': fev.Data
                        }

                    outPeople[grpId] = {
                        inData['groupBy']: grpId,
                        "People": [],
                        "FamilyEV": fevOut,
                        "Picture": None
                    }
                        
                    # Family's Picture
                    if po.Family.Picture is not None:
                        outPeople[grpId]['Picture'] = {
                            'large': po.Family.Picture.LargeUrl,
                            'medium': po.Family.Picture.MediumUrl,
                            'small': po.Family.Picture.SmallUrl,
                            'thumb': po.Family.Picture.ThumbUrl,
                            'x': po.Family.Picture.X,
                            'y': po.Family.Picture.Y
                        }
                else:
                    outPeople[grpId] = {
                        inData['groupBy']: grpId,
                        "People": []
                    }

                if useFamGeo:
                    outPeople[grpId]['geo'] = q.QuerySqlTop1(famGeoSql.format(po.FamilyId))

            outPeople[grpId]["People"].append(pr)

    Data.people = outPeople

    Data.inData = inData

    Data.rules = rules  # handy for debugging TODO remove, probably.

    Data.success = True

#