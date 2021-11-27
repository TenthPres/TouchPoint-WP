## This file is called by the WordPress Web API to determine how a person should be displayed on the public-facing
## website.  For example, you may want to prevent the full names of mission partners from being displayed, or you may
## want titles (Mrs., Rev., Dr.) to be part of a display name.  You can also prevent a person from being listed entirely.
##
## There are two pertinent variables:
## Data.Person - 	This is the Person object direct from TouchPoint.  Its attributes are documented in the documentation and
## 					the code repository.
## Data.Info - 		This is the object that gets returned for display.  It has the following attributes and default values:
##					- Exclude - bool - Default: False.  Setting this to True will prevent this person from being included
##					  in the public website completely.  This means no user account will be created for them, and if one exists,
##					  it will be deleted.
##					- FirstName - string - Default: GoesBy
##					- LastName - string - Default: LastName
##					- DisplayName - string - Default: FirstName + " " + LastName. 


Data.person
