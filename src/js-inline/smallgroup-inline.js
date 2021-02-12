let doMap = function () { // TODO move to assets/js directory... I guess?
    let colors = {
        background: '#ffffff',
        primary: '#8800a1', // dark
        tertiary: '#b800d9', // mid
        secondary: '#d11cf1', // light
        text: '#222222'
    };

    const bounds = new google.maps.LatLngBounds();

    let mapOptions = {
        zoom: 0,
        mapTypeId: google.maps.MapTypeId.ROADMAP,
        //styles: styles,
        center: {lat: 0, lng: 0},
        bounds: bounds
    };
    const m = new google.maps.Map(document.getElementById('{$mapDivId}'), mapOptions);

    for (const sgi in SmallGroups) {
        SmallGroups[sgi].marker = new google.maps.Marker({
            position: SmallGroups[sgi].geo,
            title: SmallGroups[sgi].name,
            map: m
        });
        console.log(SmallGroups[sgi].geo, SmallGroups[sgi].marker);
        bounds.extend(SmallGroups[sgi].geo);
    }
    m.fitBounds(bounds);


}

tpvm.onSmallGroupsLoad = function() {
    TP_SmallGroup.fromArray({$smallgroupsList});
    // doMap(); // TODO reconfigure/move
}