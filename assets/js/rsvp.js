"use strict";

class _TouchPointWP_RSVP_Meeting {
    constructor(meetingId) { // should only be called once.  Can safely load info.
        let xhr = new XMLHttpRequest();
        xhr.addEventListener("load", this.meetingInfoLoaded);
        // xhr.open("GET", TouchPointWP.PLUGIN_PATH + "/api/rsvp?data=mtg&mid=" + meetingId)  TODO make work for real
        xhr.open("GET", "/WordPressDemo/wp-content/plugins/TouchPoint-WP/src/TouchPoint-WP/rsvp.php?data=mtg&mid=" + meetingId)
        xhr.send();
    }

    meetingInfoLoaded() {
        console.log(this.responseText);
    }

}

class _TouchPointWP_RSVP {
    constructor(TouchPointWPObj, that) {
        this.meetings = [];
    }

    btnClick(that) {
        let mtg = this.prepMeetingFromLink(that);
    }

    preload(that) {
        this.prepMeetingFromLink(that);
    }

    /**
     *
     * @param linkObject
     * @returns _TouchPointWP_RSVP_Meeting|void
     */
    prepMeetingFromLink(linkObject) {
        let meetingId = linkObject.getAttribute('data-touchpoint-mtg') ?? null;
        meetingId = parseInt(meetingId);

        if (meetingId === null || !Number.isInteger(meetingId)) {
            console.error("Unable to create the RSVP object because the meeting ID was not provided or is invalid.")
            if (!linkObject.classList.contains('disabled')) {
                linkObject.classList.push('disabled')
            }
            linkObject.removeAttribute('href'); // remove default appearance of clickability.
            return;
        }

        if (this.meetings[meetingId] !== undefined) // if the object has already been instantiated. This is functionally a debounce on the loader.
            return this.meetings[meetingId];

        this.meetings[meetingId] = new _TouchPointWP_RSVP_Meeting(meetingId);


        return this.meetings[meetingId];
    }

}