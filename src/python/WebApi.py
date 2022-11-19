#API

import re
import json
import linecache
import sys

VERSION = "0.0.20"

sgContactEvName = "Contact"

global model, Data, q


def print_exception():  # From https://stackoverflow.com/a/20264059/2339939
    exc_type, exc_obj, tb = sys.exc_info()
    f = tb.tb_frame
    lineno = tb.tb_lineno
    filename = f.f_code.co_filename
    linecache.checkcache(filename)
    line = linecache.getline(filename, lineno, f.f_globals)
    print('EXCEPTION IN ({}, LINE {} "{}"): {}'.format(filename, lineno, line.strip(), exc_obj))


def get_person_info_for_sync(person_obj):
    if person_obj is None:
        return None

    p = model.DynamicData()
    p.Exclude = False
    p.LastName = person_obj.LastName
    p.GoesBy = person_obj.NickName if person_obj.NickName is not None else person_obj.FirstName
    p.DisplayName = " "
    p.DecoupleLocation = False

    # Person's Picture
    if person_obj.Picture is None:
        p.Picture = None
    else:
        p.Picture = {
            'large': person_obj.Picture.LargeUrl,
            'medium': person_obj.Picture.MediumUrl,
            'small': person_obj.Picture.SmallUrl,
            'thumb': person_obj.Picture.ThumbUrl,
            'x': person_obj.Picture.X,
            'y': person_obj.Picture.Y
        }

    # Email addresses
    p.Emails = []
    if person_obj.EmailAddress is not None:
        p.Emails.append(person_obj.EmailAddress)
    if person_obj.EmailAddress2 is not None:
        p.Emails.append(person_obj.EmailAddress2)

    # Send to WebPublicPerson script
    model.Data.Person = person_obj
    model.Data.Info = p
    model.CallScript('WebPublicPerson')
    p = model.Data.Info
    model.Data.Person = None
    model.Data.Info = None

    # Standardizing returns from WebPublicPerson
    if p.Exclude is True:
        return None

    if p.DisplayName == " ":  # default DisplayName
        p.DisplayName = (p.GoesBy + " " + p.LastName).strip()

    p.Usernames = map(lambda u: u.Username, person_obj.Users)

    p.PeopleId = person_obj.PeopleId
    p.FamilyId = person_obj.FamilyId
    p.GenderId = person_obj.GenderId
    return p


Data.a = Data.a.split(',')
apiCalled = False


if "Divisions" in Data.a:
    apiCalled = True
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


if "ResCodes" in Data.a:
    apiCalled = True
    rcSql = '''SELECT Id, Code, Description as Name FROM lookup.ResidentCode'''
    Data.Title = "All Resident Codes"
    Data.resCodes = q.QuerySql(rcSql, {})


if "Genders" in Data.a:
    apiCalled = True
    rcSql = '''SELECT Id, Code, Description as Name FROM lookup.Gender'''
    Data.Title = "All Genders"
    Data.genders = q.QuerySql(rcSql, {})


if "Keywords" in Data.a:
    apiCalled = True
    kwSql = '''SELECT KeywordId as Id, Code, Description as Name FROM Keyword ORDER BY Code'''
    Data.Title = "All Keywords"
    Data.keywords = q.QuerySql(kwSql, {})


if "PersonEvFields" in Data.a:
    apiCalled = True
    pevSql = '''SELECT Field, [Type], count(*) as Count,
                CONCAT('pev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
                FROM PeopleExtra WHERE [Field] NOT LIKE '%_mv'
                GROUP BY [Field], [Type] ORDER BY count(*) DESC'''
    Data.Title = "Person Extra Value Fields"
    Data.personEvFields = q.QuerySql(pevSql, {})


if "FamilyEvFields" in Data.a:
    apiCalled = True
    fevSql = '''SELECT Field, [Type], count(*) as Count,
                CONCAT('fev', SUBSTRING(CONVERT(NVARCHAR(18), HASHBYTES('MD2', CONCAT([Field], [Type])), 1), 3, 8)) Hash
                FROM FamilyExtra WHERE [Field] NOT LIKE '%_mv'
                GROUP BY [Field], [Type] ORDER BY count(*) DESC'''
    Data.Title = "Family Extra Value Fields"
    Data.familyEvFields = q.QuerySql(fevSql, {})


if "SavedSearches" in Data.a:
    apiCalled = True
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
            WHERE (q.IsPublic = 1 AND u.PeopleId <> {0}) 
                AND q.LastRun > DATEADD(DAY, -90, GETDATE()) 
                AND q.Name <> 'Draft'
            ORDER BY q.Name
        """.format(Data.PeopleId))

    Data.savedSearches.flags = model.SqlListDynamicData("""
        SELECT TOP 100 q.Name, q.QueryId FROM Query q
        WHERE q.StatusFlag = 1
        ORDER BY q.Name
    """)

    Data.Title = "Saved Searches"


if "InvsForDivs" in Data.a:
    apiCalled = True

    regex = re.compile('[^0-9,]')
    divs = regex.sub('', Data.divs)

    leadMemTypes = Data.leadMemTypes or ""
    leadMemTypes = regex.sub('', leadMemTypes)

    hostMemTypes = Data.hostMemTypes or ""
    hostMemTypes = regex.sub('', hostMemTypes)

    if hostMemTypes == "":
        hostMemTypes = "NULL"

    invSql = '''
        WITH cteTargetOrgs as
        (    
        SELECT 
                o.OrganizationId,
                o.ParentOrgId as parentInvId,
                o.LeaderMemberTypeId,
                o.Location,
                o.OrganizationName AS name,
                o.MemberCount,
                o.ClassFilled AS groupFull,
                o.GenderId,
                o.Description,
                o.RegistrationClosed AS closed,
                o.NotWeekly,
                o.RegistrationTypeId AS regTypeId,
                o.OrgPickList,
                o.MainLeaderId,
                o.RegSettingXml.exist('/Settings/AskItems') AS hasRegQuestions,
                FORMAT(o.RegStart, 'yyyy-MM-ddTHH:mm:ss') AS regStart,
                FORMAT(o.RegEnd, 'yyyy-MM-ddTHH:mm:ss') AS regEnd,
                FORMAT(o.FirstMeetingDate, 'yyyy-MM-ddTHH:mm:ss') AS firstMeeting,
                FORMAT(o.LastMeetingDate, 'yyyy-MM-ddTHH:mm:ss') AS lastMeeting
        FROM dbo.Organizations o
            WHERE o.OrganizationId = (
                    SELECT MIN(OrgId) min
                    FROM dbo.DivOrg do
                    WHERE do.OrgId = o.OrganizationId
                    AND do.DivId IN ({})
                )
            AND o.organizationStatusId = 30
        ),
        -- select all members for these organizations to avoid multiple scans of Organization members table
        cteOrganizationMembers AS 
        (SELECT 
            omi.OrganizationId,
            omi.PeopleId
            FROM dbo.OrganizationMembers omi WITH(NOLOCK)  
                INNER JOIN cteTargetOrgs o
                    ON omi.OrganizationId = o.OrganizationId),
        -- pull denom users from all target organization members
        cteMaritalStatus AS 
        (SELECT 
            omi.OrganizationId
            , SUM(CASE WHEN pi.MaritalStatusId NOT IN ( 0 ) THEN 1 ELSE 0  END)     AS marital_denom
            , SUM(CASE WHEN pi.MaritalStatusId = 20 THEN 1 ELSE 0  END)             AS marital_married
            , SUM(CASE WHEN pi.MaritalStatusId NOT IN ( 0, 20 ) THEN 1 ELSE 0  END) AS marital_single
            FROM cteOrganizationMembers omi
                INNER JOIN dbo.People pi WITH(NOLOCK)
                    ON omi.PeopleId = pi.PeopleId
                    AND pi.MaritalStatusId NOT IN ( 0 )
            GROUP BY omi.OrganizationId),
        -- pull aggregate ages for all target organization members
        cteAggAges AS 
        (SELECT OrganizationId, STRING_AGG(ag, ',') WITHIN GROUP (ORDER BY ag ASC)  AS PeopleAge
        FROM (
        SELECT omi.OrganizationId, 
                (CASE
                    WHEN pi.Age > 69 THEN '70+'
                    ELSE CONVERT(VARCHAR(2), (FLOOR(pi.Age / 10.0) * 10), 70) + 's'
                    END) as ag 
            FROM cteOrganizationMembers omi
                INNER JOIN dbo.People pi WITH(NOLOCK)
                ON omi.PeopleId = pi.PeopleId
                        WHERE pi.Age > 19
            GROUP BY omi.OrganizationId, 
                    (CASE
                    WHEN pi.Age > 69 THEN '70+'
                    ELSE CONVERT(VARCHAR(2), (FLOOR(pi.Age / 10.0) * 10), 70) + 's'
                    END)
        ) AS ag_agg
        GROUP BY ag_agg.OrganizationId       
        ),
        -- pull aggregate schedules for all target organizations
        cteSchedule AS
        (SELECT OrganizationId, STRING_AGG(sdt, ' | ') WITHIN GROUP (ORDER BY sdt ASC) AS OrgSchedule
        FROM (
            SELECT o.OrganizationId, CONCAT(FORMAT(os.NextMeetingDate, 'yyyy-MM-ddTHH:mm:ss'), '|S') as sdt 
            FROM dbo.OrgSchedule os WITH(NOLOCK)
                INNER JOIN cteTargetOrgs o
                    ON os.OrganizationId = o.OrganizationId
            UNION
            SELECT o.OrganizationId, CONCAT(FORMAT(m.meetingDate, 'yyyy-MM-ddTHH:mm:ss'), '|M') as sdt 
            FROM dbo.Meetings as m WITH(NOLOCK)
                INNER JOIN cteTargetOrgs o
                    ON m.OrganizationId = o.OrganizationId
            WHERE m.meetingDate > getdate() 
        ) s_agg
        GROUP BY s_agg.OrganizationId),
        -- pull aggregate divisions for all target organizations
        cteDivision AS 
        (SELECT OrganizationId, STRING_AGG(divId, ',') WITHIN GROUP (ORDER BY divId ASC) AS OrgDivision
        FROM (
            SELECT o.OrganizationId, do.divId 
            FROM dbo.DivOrg do WITH(NOLOCK)
                INNER JOIN cteTargetOrgs o
                    ON do.OrgId = o.OrganizationId
            ) d_agg
        GROUP BY OrganizationId),
        -- pull organization location information
        cteOrganizationLocation AS
            (
                SELECT 
                    o.[OrganizationId]            
                    , COALESCE(oai.[Latitude], paih.[Latitude], faih.[Latitude])           AS [lat]
                    , COALESCE(oai.[Longitude], paih.[Longitude], faih.[Longitude])        AS [lng]
                    , COALESCE(orc.[Description], prch.[Description], frch.[Description])  AS [resCodeName]
                FROM cteTargetOrgs o 
                    LEFT JOIN AddressInfo oai 
                        ON o.OrganizationId = oai.OrganizationId
                    LEFT JOIN Zips z 
                        ON CAST(SUBSTRING(SUBSTRING(oai.FullAddress, 8, 1000), PATINDEX('%[0-9][0-9][0-9][0-9][0-9]%', SUBSTRING(oai.FullAddress, 8, 1000)), 5) as INT) = z.ZipCode
                    LEFT JOIN lookup.ResidentCode orc
                        ON z.MetroMarginalCode = orc.id
                    LEFT JOIN dbo.People ph ON
                        (SELECT TOP 1 omh.PeopleId 
                        FROM dbo.OrganizationMembers omh 
                        WHERE o.OrganizationId = omh.OrganizationId 
                        AND omh.MemberTypeId IN ({})) = ph.PeopleId
                    LEFT JOIN dbo.Families fh ON
                        ph.FamilyId = fh.FamilyId
                    LEFT JOIN dbo.AddressInfo paih ON
                        ph.PeopleId = paih.PeopleId
                    LEFT JOIN dbo.AddressInfo faih ON
                        fh.FamilyId = faih.FamilyId
                    LEFT JOIN lookup.ResidentCode prch
                        ON ph.ResCodeId = prch.Id
                    LEFT JOIN lookup.ResidentCode frch
                        ON fh.ResCodeId = frch.Id
            )
        -- join all our ctes together
        SELECT 
            o.[OrganizationId]               AS [involvementId]
            , o.[parentInvId]                AS [parentInvId]
            , o.[LeaderMemberTypeId]         AS [leaderMemberTypeId]
            , o.[Location]                   AS [location]
            , o.[name]                       AS [name]
            , o.[MemberCount]                AS [memberCount]
            , o.[groupFull]                  AS [groupFull]
            , o.[GenderId]                   AS [genderId]
            , o.[Description]                AS [description]
            , o.[closed]                     AS [closed]
            , o.[NotWeekly]                  AS [notWeekly]
            , o.[regTypeId]                  AS [regTypeId]
            , o.[OrgPickList]                AS [orgPickList]
            , o.[MainLeaderId]               AS [mainLeaderId]
            , o.[hasRegQuestions]            AS [hasRegQuestions]
            , o.[regStart]                   AS [regStart]
            , o.[regEnd]                     AS [regEnd]
            , o.[firstMeeting]               AS [firstMeeting]
            , o.[lastMeeting]                AS [lastMeeting]
            , ISNULL(ms.marital_denom, 0)    AS [marital_denom]
            , ISNULL(ms.marital_married, 0)  AS [marital_married]
            , ISNULL(ms.marital_single, 0)   AS [marital_single]
            , aa.PeopleAge                   AS [age_groups]
            , s.OrgSchedule                  AS [occurrences]
            , d.OrgDivision                  AS [divs]
            , ol.lat                         AS [lat]
            , ol.lng                         AS [lng]
            , ol.resCodeName                 AS [resCodeName]
        FROM cteTargetOrgs o
            LEFT JOIN cteMaritalStatus ms
                ON o.OrganizationId = ms.OrganizationId
            LEFT JOIN cteAggAges aa 
                ON o.OrganizationId = aa.OrganizationId
            LEFT JOIN cteSchedule s
                ON o.OrganizationId = s.OrganizationId
            LEFT JOIN cteDivision d
                ON o.OrganizationId = d.OrganizationId
            LEFT JOIN cteOrganizationLocation ol
                ON o.OrganizationId = ol.OrganizationId
        ORDER BY o.parentInvId ASC, o.OrganizationId ASC'''.format(divs, hostMemTypes)

    groups = model.SqlListDynamicData(invSql)

    for g in groups:
        if g.age_groups is not None:
            g.age_groups = g.age_groups.split(',')

        if g.divs is not None:
            g.divs = g.divs.split(',')

        if g.occurrences is not None:
            # noinspection PyUnresolvedReferences
            g.occurrences = g.occurrences.split(' | ')
            uniqueOccurrences = []
            for i, s in enumerate(g.occurrences):
                if s[0:19] not in uniqueOccurrences:  # filter out occurrences provided by both Meetings and Schedules
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

    Data.invev = model.SqlListDynamicData(invEvSql)  # TODO move to separate request


if "MemTypes" in Data.a:
    apiCalled = True

    divs = Data.divs or ""

    regex = re.compile('[^0-9,]')
    divs = regex.sub('', divs)

    memTypeSql = '''SELECT DISTINCT om.[MemberTypeId] as id, mt.[Code] as code, mt.[Description] as description 
                    FROM OrganizationMembers om
                    JOIN DivOrg do ON om.OrganizationId = do.OrgId
                    JOIN lookup.MemberType mt ON om.[MemberTypeId] = mt.[Id]'''
    if divs != "":
        memTypeSql += " WHERE do.DivId IN ({})".format(divs)
    memTypeSql += " ORDER BY description ASC"

    Data.memTypes = model.SqlListDynamicData(memTypeSql)


if "src" in Data.a and Data.q is not None:
    apiCalled = True
    sql = None
    if len(Data.q) < 1:
        Data.people = []

    # Numeric query
    elif Data.q.isnumeric():
        sql = """SELECT TOP 10
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName) GoesBy,
                 p1.LastName,
                 SUM(score) sc,
                 -- logins + attendance
                 (SELECT COUNT(*) sc FROM Attend a
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) sc FROM ActivityLog al
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 
                       AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
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
                 SUM(score) sc,
                 -- logins + attendance
                 (SELECT COUNT(*) s1 FROM Attend a 
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) s2 FROM ActivityLog al 
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 
                       AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
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
                 SUM(score) sc,
                 -- logins + attendance
                 (SELECT COUNT(*) s1 FROM Attend a
                     WHERE a.PeopleId = p1.PeopleId AND a.MeetingDate > DATEADD(month, -3, GETDATE())) +
                 (SELECT COUNT(*) s2 FROM ActivityLog al
                     WHERE ActivityDate > DATEADD(month, -3, GETDATE()) AND LEN(Activity) > 9 
                       AND SUBSTRING(Activity, LEN(Activity) - 8, 9) = 'logged in' AND PeopleId = p1.PeopleId) as partic
             FROM (
                 SELECT p.*, 10 as score FROM People p 
                    WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') AND p.LastName LIKE '{1}%'
                 UNION
                 SELECT p.*, 8 as score FROM People p 
                    WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') AND (p.AltName LIKE '{1}%' OR p.MaidenName LIKE '{1}%')
                 UNION
                 SELECT p.*, 5 as score FROM People p 
                    WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') OR p.LastName LIKE '{1}%'
                 UNION
                 SELECT p.*, 4 as score FROM People p 
                    WHERE (p.FirstName LIKE '{0}%' OR p.NickName LIKE '{0}%') OR (p.AltName LIKE '{1}%' OR p.MaidenName LIKE '{1}')
             ) p1
             GROUP BY
                 p1.PeopleId,
                 COALESCE(p1.NickName, p1.FirstName),
                 p1.LastName
             ORDER BY SUM(score) DESC, partic DESC
             """.format(first, second)

    if sql is not None:
        Data.people = q.QuerySql(sql)


# Start POST requests

if Data.data != "":
    inData = json.loads(Data.data)['inputData']
else:
    inData = {}


if "updateScripts" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'Updating Scripts'
    Data.scriptsUpdated = 0
    for filename, content in inData.items():
        model.WriteContentPython(filename, content, "Web")
        Data.scriptsUpdated = Data.scriptsUpdated + 1
    Data.data = None

if "ident" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'Matching People'

    if inData.has_key('fid'):
        pass

    elif inData.has_key('firstName') and inData.has_key('lastName') and inData['firstName'] is not None and inData['lastName'] is not None:
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
                pass  # Family Address already has zip code
            elif p.ZipCode is not None and len(p.ZipCode) > 0 and "{}".format(p.ZipCode[0:5]) == "{}".format(inData['zip'][0:5]):
                pass  # Person Address already has zip code
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
                pass  # Phone already exists somewhere
            else:
                updates['CellPhone'] = cleanPhone

        # Update Email
        if inData.has_key('email') and inData['email'] is not None and len(inData['email']) > 5:
            if ((p.EmailAddress or "").lower() == inData['email'].lower() or
                    (p.EmailAddress2 or "").lower() == inData['email'].lower()):
                pass  # Email already exists somewhere
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

if "inv_join" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'Adding people to Involvement'

    oid = inData['invId']
    keywords = inData['keywords']
    owner = inData['owner']
    memTypes = inData['leaderTypes']
    if not owner.isnumeric():
        owner = 1
    else:
        owner = int(owner)

    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 PeopleId as contactId FROM OrganizationMembers WHERE OrganizationId = {0} AND MemberTypeId in ({2})
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName, memTypes)
    orgContact = q.QuerySqlTop1(orgContactSql)
    orgContactPid = orgContact.contactId if orgContact is not None else None  # None if not found.  Falls back to Owner

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

if "inv_contact" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    # TODO potentially merge with Join function.  Much of the code is duplicated.
    Data.Title = 'Contacting Involvement Leaders'

    oid = inData['invId']
    message = inData['message']
    keywords = inData['keywords']
    owner = inData['owner']
    memTypes = inData['leaderTypes']
    if not owner.isnumeric():
        owner = 1
    else:
        owner = int(owner)

    orgContactSql = '''
    SELECT TOP 1 IntValue as contactId FROM OrganizationExtra WHERE OrganizationId = {0} AND Field = '{1}'
    UNION
    SELECT TOP 1 PeopleId as contactId FROM OrganizationMembers WHERE OrganizationId = {0} AND MemberTypeId in ({2})
    UNION
    SELECT TOP 1 LeaderId as contactId FROM Organizations WHERE OrganizationId = {0}
    '''.format(oid, sgContactEvName, memTypes)
    orgContact = q.QuerySqlTop1(orgContactSql)
    orgContactPid = orgContact.contactId if orgContact is not None else None  # None if not found. Fall back to Owner

    Data.success = []

    p = inData['fromPerson']
    m = inData['message']
    org = model.GetOrganization(oid)
    text = """**Online Contact Form: {0}**

{1} sent the following message.  Please reach out to them and mark the task as complete.

    {2}""".format(org.name, p['goesBy'], str(m).replace("\n", "\n    "))  # being indented improves formatting

    model.CreateTaskNote(owner, p['peopleId'], orgContactPid, None, False, text, None, None, keywords)

    Data.success.append({'pid': p['peopleId'], 'invId': oid, 'cpid': orgContactPid})


if "person_wpIds" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'Updating WordPress IDs.'
    Data.success = 0

    ev = str(inData['evName'])

    for p in inData['people']:
        model.AddExtraValueInt(int(p['PeopleId']), ev, int(p['WpId']))
        Data.success += 1


if "person_contact" in Data.a and model.HttpMethod == "post":
    apiCalled = True

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


if "mtg" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'Getting Meeting Info'
    inData = model.JsonDeserialize(Data.data).inputData

    Data.success = []
    for mtg in q.QuerySql('''
    SELECT  m.meetingId as mtgId,
            m.organizationId as invId,
            m.location,
            FORMAT(m.meetingDate, 'yyyy-MM-ddTHH:mm:ss') as mtgDate,
            COALESCE(m.description, '') as description,
            m.capacity,
            o.organizationName as invName
    FROM Meetings m LEFT JOIN Organizations o ON m.organizationId = o.organizationId
    WHERE MeetingId IN ({})
    '''.format(inData.mtgRefs)):
        mtg.description = mtg.description.strip()
        if mtg.description == "":
            mtg.description = None
        if mtg.location is not None:
            mtg.location = mtg.location.strip()
        mtg.invName = mtg.invName.strip()
        Data.success.append(mtg)


if "mtg_rsvp" in Data.a and model.HttpMethod == "post":
    apiCalled = True

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


if "people_get" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.Title = 'People Query'

    rules = []
    invsMembershipsToImport = []
    invsMemSubGroupsToImport = {}
    joiner = "OR"
    if inData.has_key('groupBy'):
        sort = str(inData['groupBy'])  # hypothetically should help speed grouping
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
            if inData['inv'][iid]['memTypes'] is None:
                rules.append("IsMemberOf( Org={} ) = 1".format(iid))

            if iid not in invsMembershipsToImport:
                invsMembershipsToImport.append(iid)

                # if inData['inv'][iid]['with_memTypes'] == True:
                #    invsMemSubGroupsToImport[iid] = inData['inv'][iid]['memTypes']

    # Saved Searches (incl status flags)
    if inData.has_key('src'):
        for si in inData['src']:
            # TODO figure out a more efficient method for Status Flags
            if re.match('[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89AB][0-9a-f]{3}-[0-9a-f]{12}', si, re.I):
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

    famGeoSql = """SELECT geo.Longitude, geo.Latitude 
    FROM AddressInfo ai LEFT JOIN Geocodes geo ON ai.FullAddress = geo.Address WHERE ai.FamilyId = {}"""

    Data.Context = inData['context']

    for po in q.QueryList(rules, sort.lower()):
        pr = get_person_info_for_sync(po)

        if pr is None:  # Make sure person should not be excluded
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

                if fevSql != '':  # If grouped by family, and we have Family EVs to return
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
    Data.rules = rules  # handy for debugging
    Data.success = True


if "auth_key_set" in Data.a and model.HttpMethod == "post":
    apiCalled = True

    Data.success = 0

    if inData.has_key('apiKey') and inData['apiKey'] != '' and inData.has_key('host') and inData['host'] != '':
        Data.Title = 'Updating API Keys'

        host = inData['host']
        apiSettingKey = "wp_api_" + host.replace(".", "_")
        apiKey = model.Setting(apiSettingKey, "")
        model.SetSetting(apiSettingKey, inData["apiKey"])

        Data.success += 1

if "logout" in Data.a and model.HttpMethod == "get":
    apiCalled = True
    redir = ""
    if hasattr(Data, "r"):
        redir = Data.r

    model.Title = "Logging out..."
    model.Header = "Logging out..."
    model.Script = "<script>document.getElementById('logoutIFrame').onload = function() { window.location = \"" + redir + "\"; }</script>"
    print("<iframe id=\"logoutIFrame\" src=\"/Account/LogOff/\" style=\"position:absolute; top:-1000px; left:-10000px; width:2px; height:2px;\" ></iframe>")
    apiCalled = True

if ("login" in Data.a or Data.r != '') and model.HttpMethod == "get":  # r parameter implies desired redir after login.
    apiCalled = True
    pid = 0
    if hasattr(model, "UserPeopleId"):
        pid = model.UserPeopleId

    if pid < 1:
        model.Title = "Error"
        model.Header = "Something went wrong."
        print("<p>User not found.  If you receive this error message again, please email <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p>")

    else:
        po = model.GetPerson(pid)

        body = {
            "p": get_person_info_for_sync(po)
        }

        response = ""

        try:
            # separate method / host / path
            useSsl = True
            error = False
            defaultHost = model.Setting('wp_host', "https://www.tenth.org")
            r = Data.r if Data.r != '' else (defaultHost + '/')
            path = ""

            # add host if missing
            if not r[0:8].lower() == "https://" and not r[0:7].lower() == "http://" and not r.split('/', 1)[0].__contains__('.'):
                if r[0] == '/':
                    r = defaultHost + r
                else:
                    r = defaultHost + '/' + r

            # noinspection HttpUrlsUsage
            if r[0:8].lower() == "https://":
                r = r[8:]
            elif r[0:7].lower() == "http://":
                if model.Setting('wp_allow_insecure', 'false').lower() == 'true':
                    useSsl = False
                else:
                    response = "This request was made for http.  Https is required."
                    raise Exception("This request was made for http.  Https is required.")
                r = r[7:]

            if not r.__contains__('/'):
                r = r + "/"
            [host, path] = r.split('/', 1)
            host = host.lower()

            apiSettingKey = "wp_api_" + host.replace(".", "_")
            apiKey = model.Setting(apiSettingKey, "")

            headers = {
                "X-API-KEY": apiKey,
                "content-type": "application/json"
            }

            if apiKey == '':
                model.Title = "Error"
                model.Header = "Site not authorized"

                print("<p><b>This site is not authorized to use authentication.</b> (Error 177001)</p>")

            else:
                if Data.sToken is not '':
                    body["sToken"] = Data.sToken  # Note that this key is absent if no value is available.

                # noinspection HttpUrlsUsage
                http = "https://" if useSsl else "http://"

                response = model.RestPostJson(http + host + "/touchpoint-api/auth/token", headers, body)
                response = response.replace('﻿', '').strip()  # deal with inserted whitespaces by some plugins

                model.Title = "Login"
                model.Header = "Processing..."

                response = json.loads(response)

                if "error" in response:
                    if response['error']['code'] == 177006:
                        print("Your login session has expired.  Try again.")
                        model.Header = "Session Expired"
                    else:
                        raise Exception(response['error']['message'])

                else:
                    if ("apiKey" in response) and (model.Setting(apiKey, "") != response["apiKey"]):
                        model.SetSetting(apiSettingKey, response["apiKey"])

                    if ("wpid" in response and "wpevk" in response and
                            model.ExtraValueInt(pid, response["wpevk"]) != response["wpid"]):
                        model.AddExtraValueInt(pid, response["wpevk"], response["wpid"])

                    loginPathSettingKey = "wp_loginPath_" + host.replace(".", "_")
                    loginPath = model.Setting(loginPathSettingKey, "/wp-login.php")
                    redir = http + host + loginPath + '?loginToken=' + response["userLoginToken"]

                    if path is not '':
                        redir += "&redirect_to=" + path

                    print("REDIRECT=" + redir)

        except Exception as e:
            model.Title = "Error"
            model.Header = "Something went wrong."

            print("<p>Please email the following error message to <b>" + model.Setting("AdminMail", "the church staff") + "</b>.</p><pre>")
            print(response)
            print("</pre>")
            print("<!-- Exception Raised: ")
            print_exception()
            print(" -->")

if not apiCalled:
    model.Title = "Invalid Request"
    model.Header = "Invalid Request"
    print("<p>This script can only be called with specific parameters.</p>")

Data.VERSION = VERSION
