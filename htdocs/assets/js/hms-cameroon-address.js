/**
 * Cascading Cameroon address: région → département → commune → village.
 */
(function () {
    'use strict';
    var G = window.HMS_CAMEROON_GEO;
    if (!G || !G.departments || !G.communes) {
        return;
    }

    function $(id) {
        return document.getElementById(id);
    }

    function clearSelect(sel, placeholder) {
        while (sel.firstChild) {
            sel.removeChild(sel.firstChild);
        }
        var o = document.createElement('option');
        o.value = '';
        o.textContent = placeholder;
        sel.appendChild(o);
    }

    function addOption(sel, val, text, selected) {
        var o = document.createElement('option');
        o.value = val;
        o.textContent = text;
        if (selected) {
            o.selected = true;
        }
        sel.appendChild(o);
    }

    function fillDivisions(regionSel, divSel, keepValue) {
        var reg = regionSel.value;
        clearSelect(divSel, '— Choisir —');
        if (!reg || !G.departments[reg]) {
            return;
        }
        G.departments[reg].forEach(function (d) {
            addOption(divSel, d, d, keepValue === d);
        });
    }

    function allCommunesForRegion(reg) {
        var out = [];
        var seen = {};
        if (!reg || !G.communes[reg]) {
            return out;
        }
        Object.keys(G.communes[reg]).forEach(function (depKey) {
            var list = G.communes[reg][depKey];
            list.forEach(function (c) {
                if (c.indexOf('Autre commune') !== -1) {
                    return;
                }
                if (!seen[c]) {
                    seen[c] = true;
                    out.push(c);
                }
            });
        });
        out.sort();
        return out;
    }

    function fillCommunes(regionSel, divSel, comSel, keepValue) {
        var reg = regionSel.value;
        var dep = divSel.value;
        var placeholder = !reg
            ? '— Choisir région —'
            : (dep ? '— Choisir —' : '— Choisir (département optionnel) —');
        clearSelect(comSel, placeholder);
        if (!reg || !G.communes[reg]) {
            addOption(comSel, '__OTHER__', 'Autre commune…', false);
            return;
        }
        var list;
        if (dep && G.communes[reg][dep]) {
            list = G.communes[reg][dep];
        } else if (!dep) {
            list = allCommunesForRegion(reg);
        } else {
            addOption(comSel, '__OTHER__', 'Autre commune…', false);
            return;
        }
        list.forEach(function (c) {
            if (c.indexOf('Autre commune') !== -1) {
                return;
            }
            addOption(comSel, c, c, keepValue === c);
        });
        addOption(comSel, '__OTHER__', 'Autre commune…', keepValue === '__OTHER__' || (keepValue && list.indexOf(keepValue) === -1));
    }

    function fillVillages(regionSel, divSel, comSel, vilSel, keepValue) {
        var reg = regionSel.value;
        var dep = divSel.value;
        var comm = comSel.value;
        clearSelect(vilSel, comm ? '— Choisir —' : '— Choisir une commune —');
        if (!comm) {
            addOption(vilSel, '__OTHER__', 'Autre…', false);
            return;
        }
        var realComm = comm === '__OTHER__' ? (($('cm_commune_other') && $('cm_commune_other').value) || '').trim() : comm;
        var key = reg + '|' + dep + '|' + realComm;
        var list = (G.villageHints && G.villageHints[key]) ? G.villageHints[key] : (G.villageDefaults || []);
        list.forEach(function (v) {
            if (v === '— Choisir —') {
                return;
            }
            addOption(vilSel, v, v, keepValue === v);
        });
        addOption(vilSel, '__OTHER__', 'Autre…', keepValue === '__OTHER__' || (keepValue && list.indexOf(keepValue) === -1));
    }

    function toggleWrap(wrapId, show) {
        var w = $(wrapId);
        if (w) {
            w.style.display = show ? '' : 'none';
        }
    }

    function communeDisplayValue(comSel) {
        if (comSel.value === '__OTHER__') {
            return (($('cm_commune_other') && $('cm_commune_other').value) || '').trim();
        }
        return comSel.value;
    }

    function villageDisplayValue(vilSel) {
        if (vilSel.value === '__OTHER__') {
            return (($('cm_village_other') && $('cm_village_other').value) || '').trim();
        }
        return vilSel.value;
    }

    function updateComposed() {
        var reg = ($('cm_region') && $('cm_region').value) || '';
        var dep = ($('cm_division') && $('cm_division').value) || '';
        var com = communeDisplayValue($('cm_commune'));
        var vil = villageDisplayValue($('cm_village'));
        var det = ($('address_detail') && $('address_detail').value.trim()) || '';
        var parts = [reg, dep, com, vil, det].filter(function (x) {
            return x;
        });
        var line = parts.join(' | ');
        var prev = $('hms_cm_address_preview');
        var hid = $('hms_cm_address_composed');
        if (prev) {
            prev.value = line;
        }
        if (hid) {
            hid.value = line;
        }
    }

    function onRegionChange() {
        var rs = $('cm_region');
        var ds = $('cm_division');
        var cs = $('cm_commune');
        var vs = $('cm_village');
        fillDivisions(rs, ds, '');
        fillCommunes(rs, ds, cs, '');
        fillVillages(rs, ds, cs, vs, '');
        toggleWrap('cm_commune_other_wrap', cs.value === '__OTHER__');
        toggleWrap('cm_village_other_wrap', vs.value === '__OTHER__');
        updateComposed();
    }

    function onDivisionChange() {
        var rs = $('cm_region');
        var ds = $('cm_division');
        var cs = $('cm_commune');
        var vs = $('cm_village');
        fillCommunes(rs, ds, cs, '');
        fillVillages(rs, ds, cs, vs, '');
        toggleWrap('cm_commune_other_wrap', cs.value === '__OTHER__');
        toggleWrap('cm_village_other_wrap', vs.value === '__OTHER__');
        updateComposed();
    }

    function onCommuneChange() {
        var rs = $('cm_region');
        var ds = $('cm_division');
        var cs = $('cm_commune');
        var vs = $('cm_village');
        toggleWrap('cm_commune_other_wrap', cs.value === '__OTHER__');
        fillVillages(rs, ds, cs, vs, '');
        toggleWrap('cm_village_other_wrap', vs.value === '__OTHER__');
        updateComposed();
    }

    function onVillageChange() {
        var vs = $('cm_village');
        toggleWrap('cm_village_other_wrap', vs && vs.value === '__OTHER__');
        updateComposed();
    }

    function bindForm(form) {
        var rs = $('cm_region');
        var ds = $('cm_division');
        var cs = $('cm_commune');
        var vs = $('cm_village');
        if (!rs || !ds || !cs || !vs) {
            return;
        }
        rs.addEventListener('change', onRegionChange);
        ds.addEventListener('change', onDivisionChange);
        cs.addEventListener('change', onCommuneChange);
        vs.addEventListener('change', onVillageChange);
        var co = $('cm_commune_other');
        var vo = $('cm_village_other');
        var ad = $('address_detail');
        if (co) {
            co.addEventListener('input', updateComposed);
        }
        if (vo) {
            vo.addEventListener('input', updateComposed);
        }
        if (ad) {
            ad.addEventListener('input', updateComposed);
        }
        form.addEventListener('submit', function () {
            updateComposed();
        });
        toggleWrap('cm_commune_other_wrap', cs.value === '__OTHER__');
        toggleWrap('cm_village_other_wrap', vs.value === '__OTHER__');
        updateComposed();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-hms-cameroon-address]');
        if (!root) {
            return;
        }
        var form = root.closest('form');
        if (form) {
            bindForm(form);
        }
    });
})();
