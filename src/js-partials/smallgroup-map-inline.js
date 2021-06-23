tpvm.addEventListener('involvement_class_loaded', function() {
    TP_SmallGroup.fromArray({$smallgroupsList});
    TP_SmallGroup.initMap('{$mapDivId}');
    TP_SmallGroup.initFilters();
});