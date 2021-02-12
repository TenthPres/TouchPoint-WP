"use strict";

class TP_SmallGroup extends TP_Involvement {
    static smallGroups = [];

    constructor(obj) {
        super(obj);
        TP_SmallGroup.smallGroups.push(this);
    }

    static fromArray(invArr) {
        let ret = [];
        for (const i in invArr) {
            if (!invArr.hasOwnProperty(i)) continue;

            if (typeof invArr[i].invId === "undefined") {
                console.log("Could not parse into Small Group:", invArr[i]);
                continue;
            }

            if (typeof tpvm.involvements[invArr[i].invId] === "undefined") {
                ret.push(new TP_SmallGroup(invArr[i]))
            }
        }
        console.log(self.smallGroups);
        return ret;
    }

    static init() {
        if (typeof tpvm.onSmallGroupsLoad === "function") {
            tpvm.onSmallGroupsLoad();
            delete tpvm.onSmallGroupsLoad;
        }
    }
}

TP_SmallGroup.init();