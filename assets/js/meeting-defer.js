class TP_Meeting {

    capacity;
    description;
    location;
    /** @var Date */
    mtgDateTime = null;
    mtgId;

    /** @property inv TP_Involvement **/
    inv;

    /** @var Date */
    static _now;

    static actions = ['rsvp']

    constructor(obj) {
        this.mtgId = obj.mtgId;
        this.mtgDateTime = new Date(obj.mtgDate);
        this.description = obj.description;
        this.location = obj.location;
        this.capacity = obj.capacity;

        this.inv = TP_Involvement.fromObjArray([{name: obj.invName, invId: obj.invId}])[0];

        for (const ei in this.connectedElements) {
            if (!this.connectedElements.hasOwnProperty(ei)) continue;

            let mtg = this,
                ce = this.connectedElements[ei];

            let actionBtns = Array.from(ce.querySelectorAll('[data-tp-action]'));
            if (ce.hasAttribute('data-tp-action')) {
                // if there's a sole button, it should be added to the list so it works, too.
                actionBtns.push(ce);
            }
            for (const ai in actionBtns) {
                if (!actionBtns.hasOwnProperty(ai)) continue;
                const action = actionBtns[ai].getAttribute('data-tp-action');

                if (action === "rsvp" && this.mtgDateTime < TP_Meeting.now()) {
                    actionBtns[ai].title = "Event Past"; // i18n
                    actionBtns[ai].setAttribute("disabled", "disabled");
                    actionBtns[ai].classList.add("disabled");
                } else {
                    actionBtns[ai].classList.remove("disabled");
                    actionBtns[ai].removeAttribute("disabled");

                    // add event listener
                    if (TP_Meeting.actions.includes(action)) {
                        tpvm._utils.registerAction(action, mtg, mtg.mtgId);
                        actionBtns[ai].addEventListener('click', function (e) {
                            e.stopPropagation();
                            mtg[action + "Action"]();
                        });
                    }
                }

                // Hide preload text
                let bc = actionBtns[ai].getElementsByClassName("rsvp-btn-preload");
                for (const bi in bc) {
                    if (!bc.hasOwnProperty(bi)) continue;
                    actionBtns[ai].removeChild(bc[bi]);
                }

                // Show post-load text
                bc = actionBtns[ai].getElementsByClassName("rsvp-btn-content");
                for (const bi in bc) {
                    if (!!bc[bi].style) {
                        bc[bi].style.display = "unset";
                    }
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

    /**
     * Gets a Date object that is created when first called, probably at some point in the page load process.
     *
     * Does NOT update after it is initially set.
     *
     * @return Date
     */
    static now() {
        if (!TP_Meeting._now) {
            TP_Meeting._now = new Date(); // TODO use server time, since that's what we compare to.
        }
        return TP_Meeting._now;
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
                this.fromObjArray(res.success)
            }
        }
    }

    static className() {
        return this.name.substr(3); // refers to class name, and therefore is accessible.
    }

    static fromObjArray(mtgArr) {
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
        tpvm.trigger(this.className() + "_fromObjArray")
        return ret;
    };

    async doRsvp(data, showConfirm = true) {
        let meeting = this;
        showConfirm = !!showConfirm;

        if (typeof ga === "function") {
            ga('send', 'event', 'rsvp', 'rsvp complete', meeting.mtgId);
        }

        let res = await tpvm.postData('mtg/rsvp', {mtgId: meeting.mtgId, responses: data});
        if (res.success.length > 0) {
            let s = res.success.length === 1 ? "" : "s";
            if (showConfirm) {
                Swal.fire({
                    icon: 'success',
                    title: `Response${s} Recorded`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
                });
            }
        } else {
            console.error(res);
            if (showConfirm) {
                Swal.fire({
                    icon: 'error',
                    title: `Something strange happened.`,
                    timer: 3000,
                    customClass: tpvm._utils.defaultSwalClasses()
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
            ga('send', 'event', 'rsvp', 'rsvp btn click', meeting.mtgId);
        }

        let title = "RSVP for " + (meeting.description ?? meeting.inv.name) + "<br /><small>" + this.dateTimeString() + "</small>";

        TP_Person.DoInformalAuth(title, forceAsk).then(
            (res) => rsvpUi(meeting, res),
            () => console.log("Informal auth failed, probably user cancellation.")
        )

        function rsvpUi(meeting, people) {
            if (typeof ga === "function") {
                ga('send', 'event', 'rsvp', 'rsvp userIdentified', meeting.mtgId);
            }

            Swal.fire({
                html: `<p id="swal-tp-text">Who is coming?</p><p class="small swal-tp-instruction">Indicate who is or is not coming.  This will overwrite any existing RSVP.  <br />To avoid overwriting an existing RSVP, leave that person blank.  <br />To protect privacy, we won't show existing RSVPs here.</p></i>` + TP_Person.peopleArrayToRadio(people, ['Yes', 'No']),
                customClass: tpvm._utils.defaultSwalClasses(),
                showConfirmButton: true,
                showCancelButton: true,
                showDenyButton: true,
                title: title,
                denyButtonText: 'Add Someone Else',
                confirmButtonText: 'Submit',
                focusConfirm: false,
                preConfirm: () => {
                    let form = document.getElementById('tp_people_list_radio'),
                        hasResponses = false,
                        data = {},
                        entries = Object.fromEntries(new FormData(form));
                    for (const ei in entries) {
                        if (!entries.hasOwnProperty(ei)) continue;
                        if (typeof data[entries[ei]] === "undefined") {
                            data[entries[ei]] = [];
                            hasResponses = true;
                        }
                        data[entries[ei]].push(ei)
                    }

                    if (!hasResponses) {
                        let prompt = document.getElementById('swal-tp-text');
                        prompt.innerText = "Nothing to submit.";
                        prompt.classList.add('error')
                        return false;
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


    /**
     * @return string A formatted string for the start date/time
     */
    // noinspection JSUnusedGlobalSymbols  Used dynamically from btns.
    dateTimeString() {
        if (this.mtgDateTime === null) {
            return null
        }

        let ret;

        if (this.mtgDateTime.getFullYear() !== (new Date()).getFullYear()) {
            ret = this.mtgDateTime.toLocaleString('en-US', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                hour: 'numeric',
                minute: 'numeric'
            });
        } else {
            ret = this.mtgDateTime.toLocaleString('en-US', {
                day: 'numeric',
                month: 'long',
                // year: 'numeric', // current year isn't needed.
                hour: 'numeric',
                minute: 'numeric'
            });
        }

        return ret.replace(" PM", "pm").replace(" AM", "am").replace(":00", "");
    }
}
TP_Meeting.prototype.classShort = "m";
TP_Meeting.init();