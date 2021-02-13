tpvm.addEventListener('smallgroupsLoaded', function() {
    TP_SmallGroup.fromArray({$smallgroupsList});
    TP_SmallGroup.doMap('{$mapDivId}');
});