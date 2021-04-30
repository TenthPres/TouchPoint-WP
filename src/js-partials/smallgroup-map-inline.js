tpvm.addEventListener('smallgroups_loaded', function() {
    TP_SmallGroup.fromArray({$smallgroupsList});
    TP_SmallGroup.initMap('{$mapDivId}');
    TP_SmallGroup.initFilters();
});