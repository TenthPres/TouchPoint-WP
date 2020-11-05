# TouchPoint WP
A WordPress Plugin for integrating with [TouchPoint Church Management Software](https://github.com/bvcms/bvcms).

Developed by [Tenth Presbyterian Church](https://tenth.org) for their website and released under the AGPL License. This 
plugin is not developed or supported by TouchPoint.  While their support team is stellar, they probably won't be able to 
help you with this. 

To install this plugin for your church website, you will need significant configuration of your TouchPoint database, 
depending on which features you intend to use.  See the sections below for installation instructions for each feature.  
Also, we will not deliberately maintain backwards compatibility for older versions of WordPress or PHP.  So, please, 
keep your environment up to date. 

Some features require other plugins, which may or may not be free.  

Some features also require a TouchPoint user account with API-level access.  New TouchPoint databases do not have one by
default.  If your church doesn't have one, open a support ticket with TouchPoint to create one, referencing this plugin. 

## Features
### Crazy-Simple RSVP interface
Let folks RSVP for an event for each member in their family (and, optionally, related families) in just a few clicks.
No login required, just an email address and zip code. (If using Authentication, below, you can skip the email and zip 
code, too.)

### Authentication
Authenticate TouchPoint users to WordPress, so you can know your website users.  Optionally, this authentication can 
happen silently in the background, so that if a user is logged into TouchPoint, they are automatically logged into your 
website. 

## Future Features
- Authenticate
    - Track viewership of webpages and web resources non-anonymously.  (Know who attended your virtual worship service.)
    - Sync WordPress Permissions with TouchPoint orgs or roles. 
- Events (Requires [The Events Calendar from ModernTribe](https://theeventscalendar.com/)) 
    - Link TouchPoint Meetings with Calendar events (Requires Pro version of The Events Calendar)
    - Improved display of events in TouchPoint custom mobile apps. 
- Small Groups
    - Suggest small groups physically nearby, using geolocation.
    - Suggest demographically-targeted small groups.
    - Excessively customizable Small Group finder. 
- Global Outreach
    - Partner bios and info can be imported from TouchPoint for display on your public website, with appropriate care 
    for their security
- Bios & Contact Info
    - Generate bio pages for officers or staff members from TouchPoint People records.  

## Credit & Comments

This plugin uses PSR-12 coding standards, which are significantly different from the WordPress standards.  

Several plugins have lended structure or code snippets to this plugin:
- [Plugin Template from hlashbrooke](https://github.com/hlashbrooke/WordPress-Plugin-Template) (GPLv2)
- [AAD SSO from psignoret](https://github.com/psignoret/aad-sso-wordpress) (MIT)
