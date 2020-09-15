# TouchPoint WP
A WordPress Plugin for integrating with [TouchPoint Church Management Software](https://github.com/bvcms/bvcms).

Developed by [Tenth Presbyterian Church](https://tenth.org) for their website and released under the AGPL License. This 
plugin is not developed or supported by TouchPoint.  While their support team is excellent, they won't be able to help 
you with this. 

To install this plugin for your church website, you will need significant configuration of your TouchPoint database, 
depending somewhat on which features you intend to use.  See the sections below for installation instructions for each 
feature.  Also, we will not deliberately maintain backwards compatibility for older versions of WordPress or PHP.  So, 
please, keep your environment up to date. 

Some features require other plugins, which may or may not be free.  

## Existing Features
(literally none yet)

## Future Features
- Authenticate
    - Authenticate TouchPoint users to WordPress (That is, sign in to WordPress with TouchPoint)
        - Optionally, silently in the background. 
    - Track viewership of webpages and web resources non-anonymously.  (Know who attended your virtual worship service.)
    - Sync WordPress Permissions with TouchPoint orgs or roles. 
- Events (Requires [The Events Calendar from ModernTribe](https://theeventscalendar.com/)) 
    - Crazy-simple RSVP Interface for events
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

This plugin is based on [the Plugin Template from hlashbrooke](https://github.com/hlashbrooke/WordPress-Plugin-Template).