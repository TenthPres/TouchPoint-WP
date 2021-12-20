## This file is called by the WordPress Web API to determine how a person should be displayed on the public-facing
## website.  For example, you may want to prevent the full names of mission partners from being displayed, or you may
## want titles (Mrs., Rev., Dr.) to be part of a display name.  You can also prevent a person from being listed entirely.
##
## There are two pertinent variables:
## Data.Person - 	This is the Person object direct from TouchPoint.  Its attributes are documented in the documentation and
## 					the code repository.
## Data.Info - 		This is the object that gets returned for display.  It has the following attributes and default values:
##					- Exclude - bool - Default: False.  Setting this to True will prevent this person from being included
##					  in the public website completely.  This means no user account will be created for them and they
##					  will be excluded from any public-facing directory listing.
##					- GoesBy - string - Default: NickName if the Person has one, otherwise FirstName.  Becomes the
##					  First_Name field in WordPress.
##					- LastName - string - Default: LastName.  Becomes the Last_Name field in WordPress
##					- DisplayName - string - Default: GoesBy + " " + LastName. Becomes the Display_Name field in WordPress

goSecurityLevel = model.ExtraValueIntFamily(Data.Person.PeopleId, "Security Level")

# GO Policy:
# 1 - full names
# 2 - full first names, no last name
# 3 - first initials only
# 4 - Exclude

if goSecurityLevel > 1:
	Data.Info.LastName = ""

if goSecurityLevel > 2:
	Data.Info.GoesBy = Data.Info.GoesBy[0] + "."

if goSecurityLevel > 3:
	Data.Info.Exclude = True

