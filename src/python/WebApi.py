#API

import re

if (Data.a == "Divisions"):
    divSql = '''SELECT d.id, CONCAT(p.name, ' : ', d.name) as name FROM Division d
    JOIN Program p on d.progId = p.Id
    ORDER BY p.name, d.name'''

    Data.title = "All Divisions"
    Data.divs = q.QuerySql(divSql, {})

elif (Data.a == "InvsForDivs"):
    regex = re.compile('[^0-9\,]')
    divs = regex.sub('', Data.divs)

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
        (SELECT DISTINCT (CONVERT(VARCHAR(2), FLOOR(pi.Age / 10.0) * 10) + 's') as ag FROM OrganizationMembers omi
            LEFT JOIN People pi ON omi.PeopleId = pi.PeopleId AND omi.OrganizationId = o.organizationId
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
        AND DivId IN (''' + divs + ''')
    )
    AND o.organizationStatusId = 30'''

    groups = model.SqlListDynamicData(invSql)

    for g in groups:
        g.leaders = []

        if g.age_groups != None:
            g.age_groups = g.age_groups.split(',')

    Data.invs = groups

    # Get Extra Values in use on these involvements
    invEvSql = """SELECT DISTINCT [Field], [Type] FROM OrganizationExtra oe
                     LEFT JOIN DivOrg do ON oe.OrganizationId = do.OrgId WHERE DivId IN (15)"""

    Data.invev = model.SqlListDynamicData(invEvSql) # TODO move to separate request