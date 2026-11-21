<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$eqId = init('id');
if ($eqId == '') {
    throw new Exception('{{Équipement non défini}}');
}
$camera = reolink::byId($eqId, 'reolink');
if (!is_object($camera)) {
    throw new Exception('{{Caméra introuvable}}');
}
?>
<div id="div_patrolAlert"></div>
<div id="div_patrolSharedHint" class="alert alert-warning" style="display:none;">
  {{Ce firmware partage souvent la même ronde entre les emplacements (type E1/NVR). Préférez éditer la ronde 0. Désactiver / vider les presets peut ne pas purger certains slots.}}
</div>
<div class="input-group" style="width:100%;margin-bottom:10px;">
  <span class="input-group-addon" style="width:140px;">{{Ronde}}</span>
  <select class="form-control" id="sel_patrolId">
    <?php for ($i = 0; $i <= 5; $i++) { echo '<option value="'.$i.'">{{Ronde}} '.$i.'</option>'; } ?>
  </select>
  <span class="input-group-addon" style="min-width:220px;">
    <input type="hidden" id="cb_patrolEnable" value="0">
    <div class="btn-group btn-group-sm" id="btg_patrolEnable" data-toggle="buttons" style="margin:0;">
      <label class="btn btn-default active" id="bt_patrolDisable"><input type="radio" name="patrolEnable" value="0" autocomplete="off" checked> {{Désactiver}}</label>
      <label class="btn btn-default" id="bt_patrolEnable"><input type="radio" name="patrolEnable" value="1" autocomplete="off"> {{Activer}}</label>
    </div>
  </span>
</div>
<table class="table table-bordered table-condensed" id="table_patrolSteps">
  <thead>
    <tr>
      <th style="width:40px;">#</th>
      <th>{{Preset}}</th>
      <th style="width:110px;">{{Vitesse}}</th>
      <th style="width:110px;">{{Pause (s)}}</th>
      <th style="width:120px;">{{Actions}}</th>
    </tr>
  </thead>
  <tbody></tbody>
</table>
<div class="form-group">
  <a class="btn btn-default" id="bt_patrolAddStep"><i class="fas fa-plus"></i> {{Ajouter une étape}}</a>
  <a class="btn btn-success" id="bt_patrolSave"><i class="fas fa-save"></i> {{Enregistrer la ronde}}</a>
  <a class="btn btn-warning" id="bt_patrolStart"><i class="fas fa-play"></i> {{Démarrer}}</a>
  <a class="btn btn-danger" id="bt_patrolStop"><i class="fas fa-stop"></i> {{Arrêter}}</a>
</div>
<script>
(function () {
  var eqId = <?php echo json_encode($eqId); ?>;
  var presets = [];
  var patrols = [];

  function alertMsg(msg, level) {
    $('#div_patrolAlert').showAlert({message: msg, level: level || 'danger'});
  }

  function presetOptions(selected) {
    var html = '';
    if (!presets.length) {
      html = '<option value="">{{Aucun preset}}</option>';
    }
    presets.forEach(function (p) {
      html += '<option value="' + p.id + '"' + (String(p.id) === String(selected) ? ' selected' : '') + '>' + p.id + ' — ' + p.name + '</option>';
    });
    return html;
  }

  function renumber() {
    $('#table_patrolSteps tbody tr').each(function (i) {
      $(this).find('td:first').text(i + 1);
    });
    updateStartButton();
  }

  function updateStartButton() {
    var steps = collectSteps();
    var hasPresets = presets.length > 0;
    $('#bt_patrolStart').toggleClass('disabled', !(steps.length && hasPresets));
  }

  function detectSharedSlots() {
    var sigs = [];
    patrols.forEach(function (p) {
      if (!p || !Array.isArray(p.preset) || !p.preset.length) { return; }
      var head = p.preset.slice(0, Math.min(2, p.preset.length)).map(function (s) {
        return [s.id, s.speed, s.dwellTime].join(':');
      }).join('|');
      sigs.push(head);
    });
    var shared = false;
    if (sigs.length >= 2) {
      shared = sigs.every(function (s) { return s === sigs[0]; });
    }
    $('#div_patrolSharedHint').toggle(shared);
  }

  function addStep(step) {
    step = step || {id: (presets[0] ? presets[0].id : ''), speed: 32, dwellTime: 30};
    var tr = $('<tr/>');
    tr.append($('<td/>').text('0'));
    tr.append($('<td/>').append($('<select class="form-control input-sm patrol-preset"/>').html(presetOptions(step.id))));
    tr.append($('<td/>').append($('<input type="number" class="form-control input-sm patrol-speed" min="1" max="64"/>').val(step.speed || 32)));
    tr.append($('<td/>').append($('<input type="number" class="form-control input-sm patrol-dwell" min="1" max="300"/>').val(step.dwellTime || 30)));
    var actions = $('<td/>');
    actions.append('<a class="btn btn-xs btn-default patrol-up" title="{{Monter}}"><i class="fas fa-arrow-up"></i></a> ');
    actions.append('<a class="btn btn-xs btn-default patrol-down" title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a> ');
    actions.append('<a class="btn btn-xs btn-danger patrol-del" title="{{Supprimer}}"><i class="fas fa-trash"></i></a>');
    tr.append(actions);
    $('#table_patrolSteps tbody').append(tr);
    renumber();
  }

  function loadPatrol(id) {
    $('#table_patrolSteps tbody').empty();
    var p = null;
    patrols.forEach(function (x) { if (String(x.id) === String(id)) { p = x; } });
    setPatrolEnable(!!(p && parseInt(p.enable || 0) === 1));
    if (p && Array.isArray(p.preset)) {
      p.preset.forEach(function (s) { addStep(s); });
    }
    updateStartButton();
  }

  function setPatrolEnable(on) {
    $('#cb_patrolEnable').val(on ? '1' : '0');
    $('#bt_patrolEnable').toggleClass('active btn-success', !!on).toggleClass('btn-default', !on);
    $('#bt_patrolDisable').toggleClass('active btn-warning', !on).toggleClass('btn-default', !!on);
    $('#bt_patrolEnable input').prop('checked', !!on);
    $('#bt_patrolDisable input').prop('checked', !on);
  }

  function isPatrolEnabled() {
    return String($('#cb_patrolEnable').val()) === '1';
  }

  function collectSteps() {
    var steps = [];
    $('#table_patrolSteps tbody tr').each(function () {
      var id = $(this).find('.patrol-preset').val();
      if (id === '' || id === null) { return; }
      steps.push({
        id: parseInt(id, 10),
        speed: parseInt($(this).find('.patrol-speed').val(), 10) || 32,
        dwellTime: parseInt($(this).find('.patrol-dwell').val(), 10) || 30
      });
    });
    return steps;
  }

  function ajaxPatrol(data, okMsg) {
    data.id = eqId;
    return $.ajax({
      type: 'POST',
      url: 'plugins/reolink/core/ajax/reolink.ajax.php',
      data: data,
      dataType: 'json',
      error: function (request, status, error) { handleAjaxError(request, status, error); },
      success: function (data) {
        if (data.state != 'ok') {
          alertMsg(data.result, 'danger');
          return;
        }
        if (okMsg) { alertMsg(okMsg, 'success'); }
        if (data.result && data.result.presets) {
          presets = data.result.presets || [];
          patrols = data.result.patrols || [];
          detectSharedSlots();
          loadPatrol($('#sel_patrolId').val());
        }
      }
    });
  }

  $('#sel_patrolId').on('change', function () { loadPatrol($(this).val()); });
  $('#bt_patrolEnable').on('click', function () { setPatrolEnable(true); });
  $('#bt_patrolDisable').on('click', function () { setPatrolEnable(false); });
  $('#bt_patrolAddStep').on('click', function () { addStep(); });
  $('#table_patrolSteps').on('click', '.patrol-del', function () { $(this).closest('tr').remove(); renumber(); });
  $('#table_patrolSteps').on('change', '.patrol-preset', updateStartButton);
  $('#table_patrolSteps').on('click', '.patrol-up', function () {
    var tr = $(this).closest('tr');
    tr.prev().before(tr);
    renumber();
  });
  $('#table_patrolSteps').on('click', '.patrol-down', function () {
    var tr = $(this).closest('tr');
    tr.next().after(tr);
    renumber();
  });
  $('#bt_patrolSave').on('click', function () {
    ajaxPatrol({
      action: 'SavePatrol',
      patrolId: $('#sel_patrolId').val(),
      enable: isPatrolEnabled() ? 1 : 0,
      steps: JSON.stringify(collectSteps())
    }, '{{Ronde enregistrée}}').done(function () {
      ajaxPatrol({action: 'GetPatrolData'});
    });
  });
  $('#bt_patrolStart').on('click', function (e) {
    if ($(this).hasClass('disabled')) {
      e.preventDefault();
      alertMsg('{{Ajoutez au moins une étape et des presets avant de démarrer}}', 'warning');
      return;
    }
    ajaxPatrol({action: 'RunPatrol', op: 'StartPatrol', patrolId: $('#sel_patrolId').val()}, '{{Ronde démarrée}}');
  });
  $('#bt_patrolStop').on('click', function () {
    ajaxPatrol({action: 'RunPatrol', op: 'StopPatrol', patrolId: $('#sel_patrolId').val()}, '{{Ronde arrêtée}}');
  });

  ajaxPatrol({action: 'GetPatrolData'});
})();
</script>
