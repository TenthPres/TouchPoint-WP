# This file is called by the WordPress Web API to determine how a person should be displayed on the public-facing
# website.  For example, you may want to prevent the full names of mission partners from being displayed, or you may
# want titles (Mrs., Rev., Dr.) to be part of a display name.  You can also prevent a person from being listed entirely.
#
# There are three pertinent variables:
# Data.Person - 	This is the Person object direct from TouchPoint.  Its attributes are documented in the
# 					documentation and the code repository.
# Data.Info - 		This is the object that gets returned for display.  It has the following attributes and default values:
# 					- Exclude - bool - Default: False.  Setting this to True will prevent this person from being included
# 					  in the public website completely.  This means no user account will be created for them on
# 					  WordPress, and they will be excluded from any People Lists.
# 					- DecoupleLocation - bool - Default: False.  For Partners only.  Setting this to true will unlink the
# 					  marker on the map from the partner.  Helpful for secure partners.
# 					- GoesBy - string - Default: NickName if the Person has one, otherwise FirstName.
# 					- LastName - string - Default: LastName
# 					- DisplayName - string - Default: GoesBy + " " + LastName
# Data.Context - This indicates which part of TouchPoint-WP is querying the person information and allows you to change
# 					the response accordingly. Values include "user" and "partner".  See the documentation for a full
# 					list.  In our example below, Global Partners with a security level higher than 3 are excluded from
# 					everything, and global partner children are always excluded from Partner Lists.

global Data, model

goSecurityLevel = model.ExtraValueIntFamily(Data.Person.PeopleId, "Security Level")

# GO Policy:
# 1 - full names (no change)
# 2 - full first names, no last name
# 3 - first initials only
# 4 - Exclude from everything

if goSecurityLevel > 1:
    Data.Info.LastName = ""

if goSecurityLevel > 2:
    Data.Info.GoesBy = Data.Info.GoesBy[0] + "."
    Data.Info.DecoupleLocation = True

if goSecurityLevel > 3:
    Data.Info.Exclude = True

# For Partner listings, exclude global partner Children
if Data.Context == 'partner' and Data.Person.PositionInFamilyId == 30:
    Data.Info.Exclude = True
