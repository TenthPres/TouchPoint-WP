tpvm.addEventListener('smallgroupsLoaded', function() {
    TP_SmallGroup.fromArray({$smallgroupsList});
    TP_SmallGroup.initMap('{$mapDivId}');
    TP_SmallGroup.initFilters();
});