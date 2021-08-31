class TP_Meeting {

    mtgId = "";

    /** @property inv TP_Involvement **/
    inv;

    static actions = ['rsvp']

    constructor(obj) {
        this.mtgId = obj.mtgId;

        // this.inv = TP_Involvement.getById(obj.invId); // TODO cleanup.

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let that = this,
                ce = this.connectedElements[ei];

            let actionBtns = ce.querySelectorAll('[data-tp-action]')
            if (ce.hasAttribute('data-tp-action')) {
                // if there's a sole button, it should be added to the list so it works, too.
                const action = ce.getAttribute('data-tp-action');
                if (TP_Meeting.actions.includes(action)) {
                    ce.addEventListener('click', function (e) {
                        e.stopPropagation();
                        that[action + "Action"]();
                    });
                }
            }
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');
                if (TP_Meeting.actions.includes(action)) {
                    actionBtns[ai].addEventListener('click', function (e) {
                        e.stopPropagation();
                        that[action + "Action"]();
                    });
                }
            }
        }

        tpvm.meetings[this.mtgId] = this;
    }

    get connectedElements() {
        const sPath = '[data-tp-mtg="' + this.mtgId + '"]'
        return document.querySelectorAll(sPath);
    }

    static init() {
        tpvm.trigger('Meeting_class_loaded');

        if (document.readyState === 'loading') {  // Loading hasn't finished yet
            document.addEventListener('DOMContentLoaded', this.initMeetings());
        } else {  // `DOMContentLoaded` has already fired
            this.initMeetings();
        }
    }

    static async initMeetings() {
        let meetingsOnPage = [];

        let meetingRefs = document.querySelectorAll('[data-tp-mtg]')
        for (const mri in meetingRefs) {
            if (!meetingRefs.hasOwnProperty(mri)) continue;
            const meetingId = meetingRefs[mri].getAttribute('data-tp-mtg');
            if (!meetingsOnPage.includes(meetingId)) {
                meetingsOnPage.push(meetingId);
            }
        }

        if (meetingsOnPage.length > 0) {
            let res = await tpvm.getData("mtg", {mtgRefs: meetingsOnPage});
            if (res.hasOwnProperty("error")) {
                console.error(res.error);
            } else {
                this.fromArray(res.success)
            }
        }
    }

    static className() {
        return this.name.substr(3); // refers to class name, and therefore is accessible.
    }

    static fromArray(mtgArr) {
        let ret = [];
        for (const i in mtgArr) {
            if (!mtgArr.hasOwnProperty(i)) continue;

            if (typeof mtgArr[i].mtgId === "undefined") {
                continue;
            }

            if (typeof tpvm.meetings[mtgArr[i].mtgId] === "undefined") {
                ret.push(new this(mtgArr[i]))
            }
        }
        tpvm.trigger(this.className() + "_fromArray")
        return ret;
    };

    async doRsvp(data, showConfirm = true) {
        let meeting = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', 'rsvp', 'rsvp complete', meeting.inv.name);
        }

        let res = await tpvm.postData('mtg/rsvp', {mtgId: meeting.mtgId, responses: data});
        if (res.success.length > 0) {
            let s = res.success.length === 1 ? "" : "s";
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Response${s} Recorded`,
                    timer: 3000
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000
                });
            }
        }
    }

    /**
     *
     * @param forceAsk Used recursively to add other users to an RSVP submission.
     */
    // noinspection JSUnusedGlobalSymbols  Used dynamically from btns.
    rsvpAction(forceAsk = false) {
        let meeting = this;

        if (typeof ga === "function") {
            ga('send', 'event', 'rsvp', 'rsvp btn click', meeting.inv.name);
        }

        TP_Person.DoInformalAuth(forceAsk).then(
            (res) => rsvpUi(meeting, res),
            () => console.log("Informal auth failed, probably user cancellation.")
        )

        function rsvpUi(meeting, people) {
            if (typeof ga === "function") {
                ga('send', 'event', 'rsvp', 'rsvp userIdentified', meeting.inv.name); // TODO better naming
            }

            Swal.fire({ // TODO add event time/date/title
                html: `<p id="swal-tp-text">Who is coming?</p><p class="small swal-tp-instruction">Indicate who is or is not coming.  This will overwrite any existing RSVP.  <br />To avoid overwriting an existing RSVP, leave that person blank.  <br />To protect privacy, we won't show existing RSVPs here.</p></i>` + TP_Person.peopleArrayToRadio(people, ['Yes', 'No']),
                showConfirmButton: true,
                showCancelButton: true,
                showDenyButton: true,
                denyButtonText: 'Add Someone Else',
                confirmButtonText: 'Submit',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_people_list_radio'),
                        inputs = form.querySelectorAll("input"),
                        data = {},
                        entries = Object.fromEntries(new FormData(form));
                    for (const ei in entries) {
                        if (!entries.hasOwnProperty(ei)) continue;
                        if (typeof data[entries[ei]] === "undefined")
                            data[entries[ei]] = [];
                        data[entries[ei]].push(ei)
                    }

                    Swal.showLoading();

                    return meeting.doRsvp(data, true);
                },
                preDeny: () => {
                    meeting.rsvpAction(true);
                }
            });
        }
    }
}
TP_Meeting.init();