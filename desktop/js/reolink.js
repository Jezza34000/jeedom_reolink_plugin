
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

function printEqLogic(_eqLogic) {
  // #img_device is filled natively by plugin.template.js from the active card image.
  // Fallback for direct URL open (?id=...) before a card click.
  if (_eqLogic && _eqLogic.id && document.getElementById('img_device')) {
    var activeCard = document.querySelector('.eqLogicDisplayCard.active img')
    if (!activeCard || !activeCard.getAttribute('src')) {
      var cardImg = document.querySelector('.eqLogicDisplayCard[data-eqlogic_id="' + _eqLogic.id + '"] img')
      if (cardImg && cardImg.getAttribute('src')) {
        document.getElementById('img_device').setAttribute('src', cardImg.getAttribute('src'))
      }
    }
  }
  updateEqLogicRoleUi(_eqLogic)
}

function updateEqLogicRoleUi(_eqLogic) {
  var cfg = (_eqLogic && _eqLogic.configuration) ? _eqLogic.configuration : {}
  var ch = (typeof cfg.defined_channel !== 'undefined')
    ? cfg.defined_channel
    : $('.eqLogicAttr[data-l1key=configuration][data-l2key=defined_channel]').value()
  var hostType = String(cfg.hostExactType || cfg.exactType || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostExactType]').text() || $('.eqLogicAttr[data-l1key=configuration][data-l2key=exactType]').text() || '').trim()
  var hostChannels = parseInt(String(cfg.hostChannelNum || cfg.channelNum || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostChannelNum]').text() || $('.eqLogicAttr[data-l1key=configuration][data-l2key=channelNum]').text() || '1').trim(), 10) || 1
  var hostModel = String(cfg.hostModel || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostModel]').text() || '').trim()
  var hostName = String(cfg.hostName || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostName]').text() || '').trim()
  var emptyCh = (ch === '' || ch === null || typeof ch === 'undefined')
  var isChannelChild = !emptyCh
  var isNvrParent = emptyCh && (hostType === 'NVR' || hostChannels > 1)

  $('#div_nvrParentHint').toggle(!!isNvrParent)
  $('#lbl_accessParams').text(isNvrParent ? '{{Paramètres d\'accès au NVR}}' : '{{Paramètres d\'accès à la caméra}}')
  $('#lbl_deviceName').text(isNvrParent ? '{{Nom du NVR}}' : '{{Nom de la caméra}}')
  $('#lbl_deviceModel').text(isNvrParent ? '{{Modèle du NVR}}' : '{{Modèle}}')

  $('#fg_hostModel').toggle(!!isChannelChild)
  if (isChannelChild) {
    var hostDisplay = hostModel
    if (hostName !== '') {
      hostDisplay = hostModel !== '' ? (hostModel + ' (' + hostName + ')') : hostName
    }
    $('#span_hostModelDisplay').text(hostDisplay)
  } else {
    $('#span_hostModelDisplay').text('')
  }

  $('#fg_hostChannelNum').toggle(!!isNvrParent)
  $('#fg_supportAi').toggle(!isNvrParent)
  $('.cameraOnlyAction').toggle(!isNvrParent)

  $('.reolinkPortBadge').each(function () {
    var key = $(this).attr('data-enable-key')
    var raw = String((typeof cfg[key] !== 'undefined' ? cfg[key] : $('.eqLogicAttr[data-l1key=configuration][data-l2key=' + key + ']').text()) || '').trim().toLowerCase()
    if (raw === '1' || raw.indexOf('actif') !== -1) {
      $(this).html('<span class="label label-success" style="font-size:1em;cursor:default;">{{Actif}}</span>')
    } else if (raw === '0' || raw.indexOf('sactiv') !== -1) {
      $(this).html('<span class="label label-warning" style="font-size:1em;cursor:default;">{{Désactivé}}</span>')
    } else {
      $(this).empty()
    }
  })
}

function updateNvrParentHint() {
  updateEqLogicRoleUi(null)
}
$('.eqLogicAttr[data-l1key=configuration][data-l2key=defined_channel]').on('change keyup', updateNvrParentHint)

function syncCameraPlugin(id) {
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'SyncCameraPlugin',
      id: id || ''
    },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'})
        return
      }
      var n = (data.result && data.result.count) ? data.result.count : 0
      $('#div_alert').showAlert({message: '{{Synchronisation Caméra OK}} (' + n + ')', level: 'success'})
    }
  })
}
$('#btSyncCameraPluginAll').on('click', function () {
  syncCameraPlugin('')
})
$('#btSyncCameraPlugin').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  syncCameraPlugin(id)
})

$('#btOpenLive').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  if (typeof window.reolinkLiveStop === 'function') {
    window.reolinkLiveStop()
  }
  $('#md_modal').dialog({title: '{{Live Reolink}}'})
  $('#md_modal').load('index.php?v=d&plugin=reolink&modal=modal.live&id=' + id).dialog('open')
})

$('#btManagePatrol').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  $('#md_modal').dialog({title: '{{Gestion des rondes PTZ}}'})
  $('#md_modal').load('index.php?v=d&plugin=reolink&modal=modal.reolink&id=' + id).dialog('open')
})

$('#btGetCMD').on('click', function () {
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'CreateCMD',
      id: $('.eqLogicAttr[data-l1key=id]').value()
    },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'})
        return
      }
      $('#div_alert').showAlert({message: '{{Commandes créer avec succès}}', level: 'success'})
      window.location.reload()
    }
  })
})

$('#btCheckConnexion').on('click', function () {
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'CheckConnexion',
      id: $('.eqLogicAttr[data-l1key=id]').value()
    },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'})
        return
      }
      $('#div_alert').showAlert({message: '{{Connexion à la caméra réussie}}', level: 'success'})
    }
  })
})

$('#btGetCamNFO').on('click', function () {
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'CheckDeviceInfo',
      id: $('.eqLogicAttr[data-l1key=id]').value()
    },
    dataType: 'json',
    error: function (request, status, error) {
      handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'})
        return
      }
      $('#div_alert').showAlert({message: '{{Information récupérer avec succès}}', level: 'success'})
      window.location.reload()
    }
  })
})

/* Fonction permettant l'affichage des commandes dans l'équipement */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = {configuration: {}}
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var tr = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">'
  tr += '<td class="hidden-xs" style="min-width:50px;width:70px;">'
  tr += '<span class="cmdAttr" data-l1key="id"></span>'
  tr += '</td>'
  tr += '<td style="min-width:200px;width:350px;">'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;" title="{{Commande information liée}}">'
  tr += '<option value="">{{Aucune}}</option>'
  tr += '</select>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td style="min-width:100px;width:150px;">'
  tr += '<span class="cmdAttr label label-default" data-l1key="logicalId"></span>'
  tr += '</td>'
  tr += '<td style="min-width:150px;width:250px;">'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min.}}" title="{{Min.}}" style="width:30%;display:inline-block;"/> '
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max.}}" title="{{Max.}}" style="width:30%;display:inline-block;"/> '
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" title="{{Unité}}" style="width:30%;display:inline-block;"/>'
  tr += '</td>'
  tr += '<td style="min-width:150px;width:250px;">'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate"></span>'
  tr += '</td>'
  tr += '<td style="min-width:80px;width:140px;">'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i></td>'
  tr += '</tr>'
  $('#table_cmd tbody').append(tr)
  var tr = $('#table_cmd tbody tr').last()
  jeedom.eqLogic.buildSelectCmd({
    id: $('.eqLogicAttr[data-l1key=id]').value(),
    filter: {type: 'info'},
    error: function (error) {
      $('#div_alert').showAlert({message: error.message, level: 'danger'})
    },
    success: function (result) {
      tr.find('.cmdAttr[data-l1key=value]').append(result)
      tr.setValues(_cmd, '.cmdAttr')
      jeedom.cmd.changeType(tr, init(_cmd.subType))
    }
  })
}
