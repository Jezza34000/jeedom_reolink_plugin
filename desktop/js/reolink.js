
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

var reolinkCmdQueue = []
var reolinkLoadingEqLogic = false
var reolinkInfoOptionsHtml = ''
var reolinkCurrentEqCfg = null
var reolinkCmdCategories = ['detection', 'ptz', 'light_audio', 'notif_rec', 'image_enc', 'system']
var reolinkActiveTypeFilter = 'all'
var reolinkActiveSearch = ''
var reolinkCategoryStats = {}

if (typeof jeedom !== 'undefined' && typeof jeedom.getConfiguration === 'function' && (!jeedom.cache || jeedom.cache.getConfiguration == null)) {
  jeedom.getConfiguration({
    key: 'cmd',
    default: 0,
    async: true,
    error: function () {},
    success: function () {}
  })
}

function prePrintEqLogic(_eqlogicId) {
  reolinkCmdQueue = []
  reolinkLoadingEqLogic = true
  reolinkInfoOptionsHtml = ''
  reolinkCurrentEqCfg = null
  reolinkActiveSearch = ''
  reolinkCategoryStats = {}
  document.querySelectorAll('.in_reolinkCmdSearch').forEach(function (inp) {
    inp.value = ''
  })
  var badgeEl = document.getElementById('span_fwArchiveBadge')
  if (badgeEl) badgeEl.innerHTML = ''
  document.querySelectorAll('.reolinkCmdTable tbody').forEach(function (tbody) {
    tbody.empty()
  })
}

function saveEqLogic(_eqLogic) {
  // shortcut: omit cmd while eqLogic is still loading or queued cmds have not been flushed yet; prevents wiping DB cmds and skips 130+ SQL cmd->save() when command tabs stayed closed
  if ((reolinkLoadingEqLogic || reolinkCmdQueue.length > 0) && _eqLogic) {
    delete _eqLogic.cmd
  }
  return _eqLogic
}

function reolinkSetVisible(el, show) {
  if (!el) return
  el.style.display = show ? '' : 'none'
}

function reolinkResolveCmdCategory(_cmd) {
  var lid = String((_cmd && _cmd.logicalId) || '')
  var cat = String((reolinkCmdCatalog(lid).category) || '').trim()
  return (reolinkCmdCategories.indexOf(cat) !== -1) ? cat : 'system'
}

function reolinkIsCommandTabActive() {
  if (document.querySelector('.reolinkCmdPane.active')) return true
  return /^#commandtab/i.test(String(window.location.hash || ''))
}

function reolinkActivateTabLink(selector) {
  var link = document.querySelector(selector)
  if (link) link.click()
}

function reolinkUpdateCmdTabsAndCounts(cmdsList) {
  var stats = {}
  for (var k = 0; k < reolinkCmdCategories.length; k++) {
    stats[reolinkCmdCategories[k]] = { all: 0, info: 0, action: 0 }
  }
  if (Array.isArray(cmdsList)) {
    for (var i = 0; i < cmdsList.length; i++) {
      var c = cmdsList[i] || {}
      var cat = reolinkResolveCmdCategory(c)
      var cType = (String(c.type || 'info') === 'action') ? 'action' : 'info'
      stats[cat].all++
      stats[cat][cType]++
    }
  } else if (reolinkCmdQueue.length > 0) {
    for (var q = 0; q < reolinkCmdQueue.length; q++) {
      var qc = reolinkCmdQueue[q] || {}
      var qCat = reolinkResolveCmdCategory(qc)
      var qType = (String(qc.type || 'info') === 'action') ? 'action' : 'info'
      stats[qCat].all++
      stats[qCat][qType]++
    }
  } else {
    document.querySelectorAll('.reolinkCmdTable tbody tr.cmd').forEach(function (row) {
      var rCat = row.getAttribute('data-cmd-cat') || 'system'
      if (!stats[rCat]) stats[rCat] = { all: 0, info: 0, action: 0 }
      var rType = (row.getAttribute('data-cmd-type') === 'action') ? 'action' : 'info'
      stats[rCat].all++
      stats[rCat][rType]++
    })
  }
  reolinkCategoryStats = stats

  var firstNonEmptyCat = null
  for (var idx = 0; idx < reolinkCmdCategories.length; idx++) {
    var catKey = reolinkCmdCategories[idx]
    var countAll = stats[catKey].all
    if (countAll > 0 && firstNonEmptyCat === null) {
      firstNonEmptyCat = catKey
    }
    var li = document.querySelector('.reolinkCmdTabLi[data-cmd-cat="' + catKey + '"]')
    if (li) {
      var badge = li.querySelector('.reolinkCmdTabCount')
      if (badge) badge.textContent = String(countAll)
      reolinkSetVisible(li, countAll > 0)
    }
    var pane = document.querySelector('.reolinkCmdPane[data-cmd-cat="' + catKey + '"]')
    if (pane) {
      var bAll = pane.querySelector('.reolinkTypeCount[data-count-type="all"]')
      var bInfo = pane.querySelector('.reolinkTypeCount[data-count-type="info"]')
      var bAct = pane.querySelector('.reolinkTypeCount[data-count-type="action"]')
      if (bAll) bAll.textContent = String(stats[catKey].all)
      if (bInfo) bInfo.textContent = String(stats[catKey].info)
      if (bAct) bAct.textContent = String(stats[catKey].action)
    }
  }

  var hash = String(window.location.hash || '')
  var activePane = document.querySelector('.eqLogic .tab-pane.active')
  var activePaneCat = activePane ? activePane.getAttribute('data-cmd-cat') : null
  if (/^#commandtab/i.test(hash)) {
    var targetPane = (hash.toLowerCase() !== '#commandtab') ? document.getElementById(hash.substring(1)) : null
    var tCat = targetPane ? targetPane.getAttribute('data-cmd-cat') : null
    if (targetPane && tCat && stats[tCat] && stats[tCat].all > 0) {
      if (!targetPane.classList.contains('active')) {
        reolinkActivateTabLink('.reolinkCmdTabLi[data-cmd-cat="' + tCat + '"] > a')
      }
      return
    }
    if (firstNonEmptyCat) {
      reolinkActivateTabLink('.reolinkCmdTabLi[data-cmd-cat="' + firstNonEmptyCat + '"] > a')
    } else {
      reolinkActivateTabLink('.reolinkEqTabs a[href="#eqlogictab"], .reolinkEqTabs a[data-target="#eqlogictab"]')
    }
    return
  }
  if (activePaneCat && (!stats[activePaneCat] || stats[activePaneCat].all === 0)) {
    if (firstNonEmptyCat) {
      reolinkActivateTabLink('.reolinkCmdTabLi[data-cmd-cat="' + firstNonEmptyCat + '"] > a')
    } else {
      reolinkActivateTabLink('.reolinkEqTabs a[href="#eqlogictab"], .reolinkEqTabs a[data-target="#eqlogictab"]')
    }
  }
}

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
  reolinkInfoOptionsHtml = ''
  var cmds = (_eqLogic && Array.isArray(_eqLogic.cmd)) ? _eqLogic.cmd : []
  if (cmds.length > 0) {
    var opts = ''
    for (var i = 0; i < cmds.length; i++) {
      var c = cmds[i]
      if (c && c.type === 'info') {
        opts += '<option value="' + reolinkEscapeHtml(c.id) + '" data-type="info" data-subType="' + reolinkEscapeHtml(c.subType) + '">' + reolinkEscapeHtml(c.name) + '</option>'
      }
    }
    reolinkInfoOptionsHtml = opts
  }
  updateEqLogicRoleUi(_eqLogic)
  reolinkUpdateCmdTabsAndCounts(cmds)
  setTimeout(function () {
    reolinkLoadingEqLogic = false
    if (reolinkIsCommandTabActive()) {
      reolinkFlushCmdQueue()
    }
  }, 0)
}

function updateEqLogicRoleUi(_eqLogic) {
  if (_eqLogic && _eqLogic.configuration) {
    reolinkCurrentEqCfg = _eqLogic.configuration
  }
  var cfg = (_eqLogic && _eqLogic.configuration) ? _eqLogic.configuration : (reolinkCurrentEqCfg || {})
  var ch = (typeof cfg.defined_channel !== 'undefined')
    ? cfg.defined_channel
    : $('.eqLogicAttr[data-l1key=configuration][data-l2key=defined_channel]').value()
  var hostType = String(cfg.hostExactType || cfg.exactType || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostExactType]').text() || $('.eqLogicAttr[data-l1key=configuration][data-l2key=exactType]').text() || '').trim()
  var hostChannels = parseInt(String(cfg.hostChannelNum || cfg.channelNum || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostChannelNum]').text() || $('.eqLogicAttr[data-l1key=configuration][data-l2key=channelNum]').text() || '1').trim(), 10) || 1
  var hostModel = String(cfg.hostModel || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostModel]').text() || '').trim()
  var hostName = String(cfg.hostName || $('.eqLogicAttr[data-l1key=configuration][data-l2key=hostName]').text() || '').trim()
  var emptyCh = (ch === '' || ch === null || typeof ch === 'undefined')
  var isChannelChild = !emptyCh
  var nvrTypes = ['NVR', 'WIFI_NVR', 'HOMEHUB', 'HVR', 'DVR']
  var isNvrParent = emptyCh && (nvrTypes.indexOf(hostType.toUpperCase()) !== -1 || hostChannels > 1)

  reolinkSetVisible(document.getElementById('div_nvrParentHint'), !!isNvrParent)
  var lblAccess = document.getElementById('lbl_accessParams')
  if (lblAccess) lblAccess.textContent = isNvrParent ? '{{Paramètres d\'accès au NVR}}' : '{{Paramètres d\'accès à la caméra}}'
  var lblName = document.getElementById('lbl_deviceName')
  if (lblName) lblName.textContent = isNvrParent ? '{{Nom du NVR}}' : '{{Nom de la caméra}}'
  var lblModel = document.getElementById('lbl_deviceModel')
  if (lblModel) lblModel.textContent = isNvrParent ? '{{Modèle du NVR}}' : '{{Modèle}}'

  reolinkSetVisible(document.getElementById('fg_hostModel'), !!isChannelChild)
  var spanHost = document.getElementById('span_hostModelDisplay')
  if (spanHost) {
    if (isChannelChild) {
      var hostDisplay = hostModel
      if (hostName !== '') {
        hostDisplay = hostModel !== '' ? (hostModel + ' (' + hostName + ')') : hostName
      }
      spanHost.textContent = hostDisplay
    } else {
      spanHost.textContent = ''
    }
  }

  reolinkSetVisible(document.getElementById('fg_hostChannelNum'), !!isNvrParent)
  reolinkSetVisible(document.getElementById('fg_supportAi'), !isNvrParent)
  var cnxType = String((typeof cfg.cnxtype !== 'undefined' ? cfg.cnxtype : $('.eqLogicAttr[data-l1key=configuration][data-l2key=cnxtype]').value()) || '').trim().toLowerCase()
  var eventMode = String((typeof cfg.event_mode !== 'undefined' ? cfg.event_mode : $('.eqLogicAttr[data-l1key=configuration][data-l2key=event_mode]').value()) || '').trim().toLowerCase()
  var isBaichuanOnly = (cnxType === 'baichuan')
  reolinkSetVisible(document.getElementById('fg_eventMode'), !isBaichuanOnly)
  reolinkSetVisible(document.getElementById('fg_portOnvif'), !isBaichuanOnly && eventMode !== 'baichuan')
  reolinkSetVisible(document.getElementById('div_baichuanHint'), isBaichuanOnly)
  var lblPort = document.getElementById('lbl_accessPort')
  if (lblPort) {
    lblPort.textContent = isBaichuanOnly ? '{{Port Baichuan (TCP)}}' : '{{Port HTTP/HTTPS}}'
  }
  var inpPort = document.getElementById('inp_accessPort')
  if (inpPort) {
    inpPort.setAttribute('placeholder', isBaichuanOnly ? '9000' : '{{(facultatif)}}')
  }
  document.querySelectorAll('.cameraOnlyAction').forEach(function (el) {
    reolinkSetVisible(el, !isNvrParent)
  })
  document.querySelectorAll('.nvrOnlyAction').forEach(function (el) {
    reolinkSetVisible(el, !!isNvrParent)
  })

  document.querySelectorAll('.reolinkPortBadge').forEach(function (el) {
    var key = el.getAttribute('data-enable-key')
    var srcEl = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + key + '"]')
    var raw = String((typeof cfg[key] !== 'undefined' ? cfg[key] : (srcEl ? srcEl.textContent : '')) || '').trim().toLowerCase()
    if (raw === '1' || raw.indexOf('actif') !== -1) {
      el.innerHTML = '<span class="label label-success" style="font-size:1em;cursor:default;">{{Actif}}</span>'
    } else if (raw === '0' || raw.indexOf('sactiv') !== -1) {
      el.innerHTML = '<span class="label label-warning" style="font-size:1em;cursor:default;">{{Désactivé}}</span>'
    } else {
      el.innerHTML = ''
    }
  })

  reolinkRenderEqFirmwareBadge(cfg)
}

function reolinkRenderEqFirmwareBadge(cfg) {
  var badgeEl = document.getElementById('span_fwArchiveBadge')
  if (!badgeEl) return
  cfg = cfg || {}
  var status = String(cfg.fwArchiveStatus || '').trim()
  var latest = String(cfg.fwArchiveLatestVer || '').trim()
  var buildDate = String(cfg.fwArchiveBuildDate || '').trim()
  var dlUrl = String(cfg.fwArchiveDownloadUrl || '').trim()
  var checkedAt = String(cfg.fwArchiveCheckedAt || '').trim()
  var changelog = String(cfg.fwArchiveChangelog || '').trim()
  var safeDl = /^https:\/\//i.test(dlUrl) ? dlUrl : ''

  if (status === 'update_available' && latest !== '') {
    var tip = '{{Dernier firmware officiel}} : ' + latest
    if (buildDate !== '') tip += ' (' + buildDate + ')'
    if (changelog !== '') tip += ' — ' + changelog.replace(/\s*\n\s*/g, ' / ')
    var html = '<span class="label label-warning" title="' + reolinkEscapeHtml(tip) + '"><i class="fas fa-arrow-circle-up"></i> {{MAJ dispo}} : ' + reolinkEscapeHtml(latest) + '</span>'
    if (safeDl !== '') {
      html += ' <a class="btn btn-xs btn-success" href="' + reolinkEscapeHtml(safeDl) + '" target="_blank" rel="noopener noreferrer" title="{{Télécharger depuis le serveur officiel Reolink}}"><i class="fas fa-download"></i> {{Télécharger (.zip)}}</a>'
    }
    badgeEl.innerHTML = html
  } else if (status === 'up_to_date') {
    var okTip = checkedAt !== '' ? ('{{Vérifié le}} ' + checkedAt) : '{{Firmware à jour}}'
    badgeEl.innerHTML = '<span class="label label-success" title="' + reolinkEscapeHtml(okTip) + '"><i class="fas fa-check"></i> {{À jour}}</span>'
  } else if (status === 'not_found') {
    badgeEl.innerHTML = '<span class="label label-default" title="{{Aucun firmware officiel référencé dans l\'index GitHub pour ce couple modèle / révision matérielle}}">{{Non référencé}}</span>'
  } else {
    badgeEl.innerHTML = ''
  }
}

function updateNvrParentHint() {
  updateEqLogicRoleUi(null)
}
$('.eqLogicAttr[data-l1key=configuration][data-l2key=defined_channel]').on('change keyup', updateNvrParentHint)
$('.eqLogicAttr[data-l1key=configuration][data-l2key=cnxtype]').on('change', updateNvrParentHint)
$('.eqLogicAttr[data-l1key=configuration][data-l2key=event_mode]').on('change', updateNvrParentHint)

function reolinkFireChange(el) {
  if (!el) return
  if (typeof el.triggerEvent === 'function') {
    el.triggerEvent('change')
  } else {
    el.dispatchEvent(new Event('change', { bubbles: true }))
  }
}

function reolinkPrefillCronModal(cronExpr) {
  var tries = 0
  var timer = setInterval(function () {
    tries++
    var root = document.querySelector('#mod_insertCronValue #mod_insertCron')
    var resultSpan = root && root.querySelector('#mod_cron_span_cronResult')
    if (!root || !resultSpan) {
      if (tries > 60) {
        clearInterval(timer)
      }
      return
    }
    clearInterval(timer)
    var parts = String(cronExpr || '').trim().split(/\s+/)
    var mode = root.querySelector('#mod_cron_sel_scheduleMode')
    if (!mode) return
    if (parts.length === 6) {
      mode.value = 'once'
      reolinkFireChange(mode)
      var minute = parts[0], hour = parts[1], day = parts[2], month = parts[3], year = parts[5]
      var pad = function (v) { return (String(v).length === 1 ? '0' : '') + v }
      var dateInput = root.querySelector('#mod_cron_in_dateScenarioTrigger')
      if (dateInput) {
        dateInput.value = year + '-' + pad(month) + '-' + pad(day) + ' ' + pad(hour) + ':' + pad(minute) + ':00'
        reolinkFireChange(dateInput)
      }
      var resOnce = root.querySelector('#mod_cron_span_cronResult')
      if (resOnce) resOnce.textContent = parts.join(' ')
      return
    }
    if (parts.length !== 5) return
    mode.value = 'repete'
    reolinkFireChange(mode)
    var period = root.querySelector('select[name="cron-period"]')
    if (!period) return
    var presets = {
      '* * * * *': 'minute',
      '*/5 * * * *': '*/5 * * * *',
      '*/10 * * * *': '*/10 * * * *',
      '*/15 * * * *': '*/15 * * * *',
      '*/20 * * * *': '*/20 * * * *',
      '*/30 * * * *': '*/30 * * * *'
    }
    var expr = parts.join(' ')
    var setSel = function (sel, val) {
      var s = root.querySelector(sel)
      if (s) {
        s.value = String(parseInt(val, 10))
        reolinkFireChange(s)
      }
    }
    var hasOpt = function (val) {
      return Array.prototype.some.call(period.options, function (o) { return o.value === val })
    }
    if (presets[expr] && hasOpt(presets[expr])) {
      period.value = presets[expr]
      reolinkFireChange(period)
    } else if (/^\d{1,2}\s\*\s\*\s\*\s\*$/.test(expr)) {
      period.value = 'hour'
      reolinkFireChange(period)
      setSel('select[name="cron-mins"]', parts[0])
    } else if (/^\d{1,2}\s\d{1,2}\s\*\s\*\s\*$/.test(expr)) {
      period.value = 'day'
      reolinkFireChange(period)
      setSel('select.cron-time-min', parts[0])
      setSel('select.cron-time-hour', parts[1])
    } else if (/^\d{1,2}\s\d{1,2}\s\*\s\*\s\d{1,2}$/.test(expr)) {
      period.value = 'week'
      reolinkFireChange(period)
      setSel('select.cron-time-min', parts[0])
      setSel('select.cron-time-hour', parts[1])
      setSel('select[name="cron-dow"]', parts[4])
    } else if (/^\d{1,2}\s\d{1,2}\s\d{1,2}\s\*\s\*$/.test(expr)) {
      period.value = 'month'
      reolinkFireChange(period)
      setSel('select.cron-time-min', parts[0])
      setSel('select.cron-time-hour', parts[1])
      setSel('select[name="cron-dom"]', parts[2])
    } else if (/^\d{1,2}\s\d{1,2}\s\d{1,2}\s\d{1,2}\s\*$/.test(expr)) {
      period.value = 'year'
      reolinkFireChange(period)
      setSel('select.cron-time-min', parts[0])
      setSel('select.cron-time-hour', parts[1])
      setSel('select[name="cron-dom"]', parts[2])
      setSel('select[name="cron-month"]', parts[3])
    } else {
      if (!hasOpt(expr)) {
        var opt = document.createElement('option')
        opt.value = expr
        opt.textContent = '{{Valeur actuelle}} (' + expr + ')'
        period.insertBefore(opt, period.firstChild)
      }
      period.value = expr
      reolinkFireChange(period)
    }
    var res = root.querySelector('#mod_cron_span_cronResult')
    if (res) res.textContent = expr
  }, 50)
}

$('#bt_cronGenerator').on('click', function () {
  var $field = $('.eqLogicAttr[data-l1key=configuration][data-l2key=autorefresh]')
  var cronExpr = String($field.value() || '').trim()
  if (cronExpr === '') {
    cronExpr = String($field.attr('placeholder') || '*/15 * * * *').trim()
  }
  jeedom.getCronSelectModal({}, function (result) {
    $field.value(result.value)
  })
  reolinkPrefillCronModal(cronExpr)
})

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
$('#btHealthReolink').on('click', function () {
  $('#md_modal').dialog({title: '{{Santé Reolink}}'})
  $('#md_modal').load('index.php?v=d&plugin=reolink&modal=health').dialog('open')
})
$('#btSyncCameraPlugin').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  syncCameraPlugin(id)
})

$('#btSyncNvrChannels').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  $('#div_alert').showAlert({message: '{{Découverte et création des caméras du NVR en cours...}}', level: 'info'})
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'SyncNvrChannels',
      id: id
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
      var r = data.result || {}
      var created = parseInt(r.created || 0, 10)
      var existing = parseInt(r.existing || 0, 10)
      var msg = '{{Synchronisation NVR terminée}} : ' + created + ' {{caméra(s) créée(s)}}, ' + existing + ' {{déjà présente(s)}}'
      $('#div_alert').showAlert({message: msg, level: 'success'})
      if (created > 0) {
        setTimeout(function () {
          var url = 'index.php?v=d&m=reolink&p=reolink&id=' + encodeURIComponent(id)
          if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.loadPage === 'function') {
            jeedomUtils.loadPage(url)
          } else {
            window.location.href = url
          }
        }, 1200)
      }
    }
  })
})

function reolinkOpenFirmwareModal(report) {
  var devices = (report && Array.isArray(report.devices)) ? report.devices : []
  var updatesCount = parseInt((report && report.updatesCount) || 0, 10)
  var fetchedAt = String((report && report.fetchedAt) || '')
  var repoUrl = String((report && report.repoUrl) || 'https://github.com/AT0myks/reolink-fw-archive')
  var safeRepoUrl = /^https:\/\//i.test(repoUrl) ? repoUrl : 'https://github.com/AT0myks/reolink-fw-archive'

  var html = '<div style="text-align:left;">'
  html += '<p>'
  if (updatesCount > 0) {
    html += '<span class="label label-warning" style="font-size:0.95em;"><i class="fas fa-arrow-circle-up"></i> ' + updatesCount + ' {{mise(s) à jour disponible(s)}}</span>'
  } else {
    html += '<span class="label label-success" style="font-size:0.95em;"><i class="fas fa-check"></i> {{Tous les équipements référencés sont à jour}}</span>'
  }
  if (fetchedAt !== '') {
    html += ' &nbsp;<span class="text-muted" style="font-size:12px;">({{Index synchronisé :}} ' + reolinkEscapeHtml(fetchedAt) + (report && report.stale ? ' - cache local' : '') + ')</span>'
  }
  html += '</p>'
  html += '<p class="text-muted" style="font-size:12px;">{{Source de l\'index :}} <a href="' + reolinkEscapeHtml(safeRepoUrl) + '" target="_blank" rel="noopener noreferrer">AT0myks/reolink-fw-archive</a> — {{seuls les téléchargements HTTPS hébergés sur les serveurs officiels Reolink sont proposés. Décompressez l\'archive .zip avant d\'appliquer le fichier .pak/.paks dans l\'interface Reolink.}}</p>'
  html += '<div style="max-height:60vh;overflow-y:auto;">'
  html += '<table class="table table-bordered table-condensed">'
  html += '<thead><tr>'
  html += '<th>{{Équipement}}</th>'
  html += '<th>{{Modèle / Hardware}}</th>'
  html += '<th>{{Installé}}</th>'
  html += '<th>{{Dernier officiel}}</th>'
  html += '<th>{{État}}</th>'
  html += '<th>{{Téléchargement}}</th>'
  html += '</tr></thead><tbody>'

  for (var i = 0; i < devices.length; i++) {
    var d = devices[i] || {}
    var stBadge = ''
    if (d.status === 'update_available') {
      stBadge = '<span class="label label-warning"><i class="fas fa-arrow-circle-up"></i> {{MAJ disponible}}</span>'
    } else if (d.status === 'up_to_date') {
      stBadge = '<span class="label label-success"><i class="fas fa-check"></i> {{À jour}}</span>'
    } else if (d.status === 'missing_info') {
      stBadge = '<span class="label label-default">{{Infos caméra manquantes}}</span>'
    } else {
      stBadge = '<span class="label label-default">{{Non référencé}}</span>'
    }

    var latestHtml = d.latestVer ? ('<code>' + reolinkEscapeHtml(d.latestVer) + '</code>') : '-'
    if (d.buildDate) {
      latestHtml += '<br><small class="text-muted">' + reolinkEscapeHtml(d.buildDate) + '</small>'
    }
    if (Array.isArray(d.changelog) && d.changelog.length > 0) {
      latestHtml += '<div class="text-muted" style="font-size:11px;margin-top:3px;max-width:340px;">' + reolinkEscapeHtml(d.changelog.join(' • ')) + '</div>'
    }

    var safeDl = (d.downloadUrl && /^https:\/\//i.test(d.downloadUrl)) ? d.downloadUrl : ''
    var dlHtml = '-'
    if (safeDl !== '') {
      var btnClass = (d.status === 'update_available') ? 'btn-success' : 'btn-default'
      dlHtml = '<a class="btn btn-xs ' + btnClass + '" href="' + reolinkEscapeHtml(safeDl) + '" target="_blank" rel="noopener noreferrer"><i class="fas fa-download"></i> {{Télécharger (.zip)}}</a>'
    }

    var hwInfo = reolinkEscapeHtml(d.model || '-')
    if (d.hardVer) {
      hwInfo += '<br><small class="text-muted">' + reolinkEscapeHtml(d.hardVer) + '</small>'
    }

    html += '<tr>'
    html += '<td><strong>' + reolinkEscapeHtml(d.humanName || d.name || ('#' + d.id)) + '</strong>' + (d.isEnable === false ? ' <span class="label label-default">{{Inactif}}</span>' : '') + '</td>'
    html += '<td>' + hwInfo + '</td>'
    html += '<td>' + (d.installedVer ? ('<code>' + reolinkEscapeHtml(d.installedVer) + '</code>') : '-') + '</td>'
    html += '<td>' + latestHtml + '</td>'
    html += '<td>' + stBadge + '</td>'
    html += '<td>' + dlHtml + '</td>'
    html += '</tr>'
  }
  html += '</tbody></table></div></div>'

  if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.alert === 'function') {
    jeeDialog.alert({
      title: '{{Vérification des firmwares officiels Reolink}}',
      message: html,
      width: '82vw'
    })
    return
  }
  if (typeof bootbox !== 'undefined' && typeof bootbox.alert === 'function') {
    bootbox.alert({
      title: '{{Vérification des firmwares officiels Reolink}}',
      size: 'large',
      message: html
    })
  }
}

$('#btCheckFirmwareAll').on('click', function () {
  $('#div_alert').showAlert({message: '{{Vérification des mises à jour firmware sur l\'index GitHub en cours...}}', level: 'info'})
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'CheckFirmwareUpdates',
      force: 1
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
      $('#div_alert').hideAlert()
      reolinkOpenFirmwareModal(data.result || {})
    }
  })
})

$('#btCheckEqFirmware').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  $('#div_alert').showAlert({message: '{{Vérification du firmware en cours...}}', level: 'info'})
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'CheckFirmwareUpdates',
      id: id,
      force: 1
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
      var res = data.result || {}
      var dev = (Array.isArray(res.devices) && res.devices.length > 0) ? res.devices[0] : null
      if (dev) {
        reolinkCurrentEqCfg = reolinkCurrentEqCfg || {}
        reolinkCurrentEqCfg.fwArchiveStatus = dev.status || ''
        reolinkCurrentEqCfg.fwArchiveLatestVer = dev.latestVer || ''
        reolinkCurrentEqCfg.fwArchiveBuildDate = dev.buildDate || ''
        reolinkCurrentEqCfg.fwArchiveDownloadUrl = dev.downloadUrl || ''
        reolinkCurrentEqCfg.fwArchiveCheckedAt = dev.checkedAt || ''
        reolinkCurrentEqCfg.fwArchiveChangelog = Array.isArray(dev.changelog) ? dev.changelog.join('\n') : ''
        reolinkRenderEqFirmwareBadge(reolinkCurrentEqCfg)
        if (dev.status === 'update_available') {
          $('#div_alert').hideAlert()
          reolinkOpenFirmwareModal(res)
        } else if (dev.status === 'up_to_date') {
          $('#div_alert').showAlert({message: '{{Le firmware de cet équipement est à jour}} (' + (dev.installedVer || '') + ')', level: 'success'})
        } else if (dev.status === 'missing_info') {
          $('#div_alert').showAlert({message: '{{Modèle ou révision matérielle manquants : cliquez d\'abord sur Récupérer les infos}}', level: 'warning'})
        } else {
          $('#div_alert').showAlert({message: '{{Aucun firmware officiel référencé dans l\'index GitHub pour ce modèle / hardware}}', level: 'warning'})
        }
      }
    }
  })
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
      $('#div_alert').showAlert({message: '{{Commandes créées avec succès}}', level: 'success'})
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

function reolinkOpenDiagModal(report) {
  var sum = (report && report.summary) ? report.summary : {}
  var snap = (report && report.snapshot) ? report.snapshot : {}
  var xmlText = String((report && report.xmlReport) || '')
  var modelLabel = sum.channelModel || sum.hostModel || '-'
  var fwLabel = sum.channelFirmVer || sum.hostFirmVer || '-'
  var snapBadge = snap.ok
    ? ('<span class="label label-success">OK (' + snap.bytes + ' octets JPEG)</span>')
    : ('<span class="label label-warning">' + reolinkEscapeHtml(snap.error || 'Indisponible') + '</span>')

  var html = '<div style="text-align:left;">'
  html += '<p><strong>{{Modèle}} :</strong> ' + reolinkEscapeHtml(modelLabel)
    + ' &nbsp;|&nbsp; <strong>{{Firmware}} :</strong> ' + reolinkEscapeHtml(fwLabel)
    + ' &nbsp;|&nbsp; <strong>{{Commandes OK}} :</strong> ' + parseInt(sum.commandsOk || 0, 10)
    + ' / ' + (parseInt(sum.commandsOk || 0, 10) + parseInt(sum.commandsRejected || 0, 10))
    + ' &nbsp;|&nbsp; <strong>{{Snapshot cmd 109}} :</strong> ' + snapBadge + '</p>'
  html += '<p class="text-muted" style="font-size:12px;">{{Les mots de passe, UID, numéros de série et adresses MAC sont automatiquement masqués (***REDACTED***).}}</p>'
  html += '<div style="margin-bottom:8px;">'
  html += '<a class="btn btn-sm btn-primary" id="btCopyBaichuanXml"><i class="fas fa-copy"></i> {{Copier le XML}}</a> '
  html += '<a class="btn btn-sm btn-success" id="btDownloadBaichuanXml"><i class="fas fa-file-download"></i> {{Télécharger (.xml)}}</a>'
  html += '</div>'
  html += '<textarea id="taBaichuanDiagXml" class="form-control" rows="16" readonly style="font-family:monospace;font-size:11px;white-space:pre;"></textarea>'
  html += '</div>'

  var bindActions = function () {
    var ta = document.getElementById('taBaichuanDiagXml')
    if (ta) ta.value = xmlText
    var btCopy = document.getElementById('btCopyBaichuanXml')
    if (btCopy) {
      btCopy.onclick = function (ev) {
        ev.preventDefault()
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(xmlText)
        } else if (ta) {
          ta.select()
          document.execCommand('copy')
        }
        $('#div_alert').showAlert({message: '{{Rapport XML copié dans le presse-papiers}}', level: 'success'})
      }
    }
    var btDl = document.getElementById('btDownloadBaichuanXml')
    if (btDl) {
      btDl.onclick = function (ev) {
        ev.preventDefault()
        var blob = new Blob([xmlText], {type: 'application/xml;charset=utf-8'})
        var url = URL.createObjectURL(blob)
        var a = document.createElement('a')
        var safeModel = String(modelLabel || 'reolink').replace(/[^a-zA-Z0-9_-]+/g, '_')
        a.href = url
        a.download = 'reolink_baichuan_diag_' + safeModel + '.xml'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
        setTimeout(function () { URL.revokeObjectURL(url) }, 1000)
      }
    }
  }

  if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.alert === 'function') {
    jeeDialog.alert({
      title: '{{Diagnostic Baichuan (port 9000)}}',
      message: html,
      width: '80vw'
    })
    setTimeout(bindActions, 50)
    return
  }
  if (typeof bootbox !== 'undefined' && typeof bootbox.alert === 'function') {
    bootbox.alert({
      title: '{{Diagnostic Baichuan (port 9000)}}',
      size: 'large',
      message: html
    })
    setTimeout(bindActions, 50)
  }
}

$('#btBaichuanDiag').on('click', function () {
  var id = $('.eqLogicAttr[data-l1key=id]').value()
  if (id == '') {
    $('#div_alert').showAlert({message: '{{Veuillez d\'abord sauvegarder l\'équipement}}', level: 'danger'})
    return
  }
  $('#div_alert').showAlert({message: '{{Diagnostic Baichuan en cours (lecture seule sur port 9000)...}}', level: 'info'})
  $.ajax({
    type: 'POST',
    url: 'plugins/reolink/core/ajax/reolink.ajax.php',
    data: {
      action: 'BaichuanDiagnostic',
      id: id
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
      $('#div_alert').showAlert({message: '{{Diagnostic Baichuan terminé}}', level: 'success'})
      reolinkOpenDiagModal(data.result)
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
      $('#div_alert').showAlert({message: '{{Informations récupérées avec succès}}', level: 'success'})
      window.location.reload()
    }
  })
})

function reolinkEscapeHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function reolinkCmdCatalog(logicalId) {
  var map = window.REOLINK_CMD_HELP || {}
  return map[String(logicalId || '')] || {}
}

function reolinkCmdValuesText(_cmd, catalog) {
  var cfg = _cmd.configuration || {}
  var listValue = String(cfg.listValue || '').trim()
  if (listValue !== '') {
    var labels = []
    listValue.split(';').forEach(function (pair) {
      var bits = pair.split('|')
      if (bits.length === 2) {
        labels.push(bits[0] + ' = ' + bits[1])
      } else if (pair !== '') {
        labels.push(pair)
      }
    })
    if (labels.length) {
      return labels.join(', ')
    }
  }
  var valuesHelp = String((catalog && catalog.valuesHelp) || cfg.valuesHelp || '').trim()
  if (valuesHelp !== '') {
    return valuesHelp
  }
  if (String(_cmd.subType || '') === 'slider' || (cfg.minValue !== undefined && cfg.minValue !== '' && cfg.maxValue !== undefined && cfg.maxValue !== '')) {
    var minV = (cfg.minValue !== undefined && cfg.minValue !== '') ? cfg.minValue : '?'
    var maxV = (cfg.maxValue !== undefined && cfg.maxValue !== '') ? cfg.maxValue : '?'
    var unit = String(_cmd.unite || '').trim()
    return '{{Curseur de}} ' + minV + ' {{à}} ' + maxV + (unit ? (' ' + unit) : '')
  }
  if (String(_cmd.type || '') === 'info' && String(_cmd.subType || '') === 'binary') {
    return '0 = {{Inactif}} / {{Non}}, 1 = {{Actif}} / {{Oui}}'
  }
  return ''
}

function reolinkCmdHelpData(_cmd) {
  var cfg = _cmd.configuration || {}
  var logicalId = String(_cmd.logicalId || '')
  var catalog = reolinkCmdCatalog(logicalId)
  var help = String(cfg.help || catalog.help || '').trim()
  var values = reolinkCmdValuesText(_cmd, catalog)
  var tipParts = []
  if (help !== '') {
    tipParts.push(help)
  }
  if (values !== '') {
    tipParts.push('{{Valeurs}} : ' + values)
  }
  return {
    logicalId: logicalId,
    name: String(_cmd.name || ''),
    help: help,
    values: values,
    title: tipParts.map(reolinkEscapeHtml).join('<br>')
  }
}

function reolinkShowCmdHelp(data) {
  var html = ''
  html += '<div style="text-align:left;">'
  if (data.name) {
    html += '<p><strong>' + reolinkEscapeHtml(data.name) + '</strong></p>'
  }
  if (data.logicalId) {
    html += '<p><code>' + reolinkEscapeHtml(data.logicalId) + '</code></p>'
  }
  if (data.help) {
    html += '<p>' + reolinkEscapeHtml(data.help) + '</p>'
  }
  if (data.values) {
    html += '<p><strong>{{Valeurs}}</strong> : ' + reolinkEscapeHtml(data.values) + '</p>'
  }
  if (!data.help && !data.values) {
    html += '<p>{{Aucune aide disponible pour cette commande}}</p>'
  }
  html += '</div>'
  if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.alert === 'function') {
    jeeDialog.alert({
      title: '{{Aide commande}}',
      message: html
    })
    return
  }
  if (typeof bootbox !== 'undefined' && typeof bootbox.alert === 'function') {
    bootbox.alert({
      title: '{{Aide commande}}',
      message: html
    })
    return
  }
  $('#div_alert').showAlert({message: (data.help || '') + (data.values ? ('<br>' + data.values) : ''), level: 'info'})
}

/* Fonction permettant l'affichage des commandes dans l'équipement */
function reolinkBuildCmdRow(_cmd) {
  if (!isset(_cmd)) {
    _cmd = {configuration: {}}
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }
  var cfg = _cmd.configuration
  var disp = _cmd.display || {}
  var cmdType = String(init(_cmd.type) || 'info')
  var cmdSubType = String(init(_cmd.subType) || '')
  var cmdValue = init(_cmd.value)
  var needInfoSelect = (cmdType === 'action') || (cmdValue !== '' && cmdValue !== null && typeof cmdValue !== 'undefined')
  var helpData = reolinkCmdHelpData(_cmd)

  var html = '<td class="hidden-xs" style="min-width:50px;width:70px;">'
  html += '<span class="cmdAttr" data-l1key="id">' + reolinkEscapeHtml(init(_cmd.id)) + '</span>'
  html += '</td>'
  html += '<td style="min-width:200px;width:350px;">'
  html += '<div class="input-group">'
  html += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">'
  html += '<span class="input-group-btn">'
  html += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon"><i class="fas fa-icons"></i></a>'
  html += '</span>'
  html += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;">' + String(disp.icon || '') + '</span>'
  if (helpData.title !== '') {
    html += '<span class="input-group-addon" style="padding:0 6px;">'
    html += '<a href="#" class="cmdHelpBtn tooltips" title="' + helpData.title + '" style="color:var(--al-info-color);">'
    html += '<i class="fas fa-question-circle" style="color:var(--al-info-color);"></i>'
    html += '</a></span>'
  }
  html += '</div>'
  html += '<span class="cmdAttr" data-l1key="configuration" data-l2key="help" style="display:none;">' + reolinkEscapeHtml(init(cfg.help)) + '</span>'
  html += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none;margin-top:5px;">'
  html += '<option value="">{{Aucune}}</option>'
  if (needInfoSelect && reolinkInfoOptionsHtml !== '') {
    html += reolinkInfoOptionsHtml
  }
  html += '</select>'
  html += '</td>'
  html += '<td>'
  html += '<span class="type" type="' + reolinkEscapeHtml(cmdType) + '">' + jeedom.cmd.availableType() + '</span>'
  html += '<span class="subType" subType="' + reolinkEscapeHtml(cmdSubType) + '"></span>'
  html += '</td>'
  html += '<td style="min-width:100px;width:150px;">'
  html += '<span class="cmdAttr label label-default" data-l1key="logicalId">' + reolinkEscapeHtml(init(_cmd.logicalId)) + '</span>'
  html += '</td>'
  html += '<td style="min-width:150px;width:250px;">'
  html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min.}}" style="width:30%;display:inline-block;"/> '
  html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max.}}" style="width:30%;display:inline-block;"/> '
  html += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}" style="width:30%;display:inline-block;"/>'
  html += '</td>'
  html += '<td style="min-width:150px;width:250px;">'
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked/>{{Afficher}}</label> '
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked/>{{Historiser}}</label> '
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="display" data-l2key="invertBinary"/>{{Inverser}}</label>'
  html += '</td>'
  html += '<td>'
  html += '<span class="cmdAttr" data-l1key="htmlstate">' + String(init(_cmd.htmlstate)) + '</span>'
  html += '</td>'
  html += '<td style="min-width:80px;width:140px;">'
  if (is_numeric(_cmd.id)) {
    html += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    html += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  html += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i></td>'

  var row = document.createElement('tr')
  row.className = 'cmd'
  var resolvedCat = reolinkResolveCmdCategory(_cmd)
  row.setAttribute('data-cmd_id', init(_cmd.id))
  row.setAttribute('data-cmd-cat', resolvedCat)
  row.setAttribute('data-cmd-type', (cmdType === 'action') ? 'action' : 'info')
  row.setAttribute('data-logical-id', String(init(_cmd.logicalId)))
  row.innerHTML = html
  row._reolinkHelp = helpData

  row.querySelector('input[data-l1key="name"]').value = init(_cmd.name)
  var selType = row.querySelector('select[data-l1key="type"]')
  if (selType && cmdType !== '') selType.value = cmdType
  if (needInfoSelect && cmdValue !== '') {
    row.querySelector('select[data-l1key="value"]').value = String(cmdValue)
  }
  row.querySelector('input[data-l2key="minValue"]').value = init(cfg.minValue)
  row.querySelector('input[data-l2key="maxValue"]').value = init(cfg.maxValue)
  row.querySelector('input[data-l1key="unite"]').value = init(_cmd.unite)
  if (init(_cmd.isVisible) !== '') {
    row.querySelector('input[data-l1key="isVisible"]').checked = (String(_cmd.isVisible) === '1')
  }
  if (init(_cmd.isHistorized) !== '') {
    row.querySelector('input[data-l1key="isHistorized"]').checked = (String(_cmd.isHistorized) === '1')
  }
  if (init(disp.invertBinary) !== '') {
    row.querySelector('input[data-l2key="invertBinary"]').checked = (String(disp.invertBinary) === '1')
  }

  jeedom.cmd.changeType(row, cmdSubType)
  return row
}

function reolinkBindLiveCmdStates(container) {
  if (!container || typeof jeedom === 'undefined' || !jeedom.cmd || typeof jeedom.cmd.addUpdateFunction !== 'function') return
  container.querySelectorAll('.cmdTableState').forEach(function (_cmdState) {
    jeedom.cmd.addUpdateFunction(_cmdState.getAttribute('data-cmd_id'), function (_options) {
      _options.value = String(_options.value).replace(/<[^>]*>?/gm, '')
      var cmd = document.querySelector('.cmdTableState[data-cmd_id="' + _options.cmd_id + '"]')
      if (cmd === null) return
      var title = '{{Date de collecte}} : ' + _options.collectDate + '<br/>{{Date de valeur}} ' + _options.valueDate
      if (_options.value.length > 50) {
        title += ' - ' + _options.value
      }
      cmd.setAttribute('title', title)
      cmd.empty().innerHTML = _options.value.substring(0, 50) + ' ' + _options.raw_unit
      cmd.style.color = 'var(--logo-primary-color)'
      setTimeout(function () {
        cmd.style.color = null
        if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.initTooltips === 'function') {
          jeedomUtils.initTooltips(cmd.parentElement || cmd)
        }
      }, 1000)
    })
  })
}

function reolinkNormSearchText(str) {
  if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.normTextLower === 'function') {
    return jeedomUtils.normTextLower(String(str || ''))
  }
  return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
}

function reolinkSyncFilterUi() {
  var activePane = document.querySelector('.reolinkCmdPane.active')
  if (activePane && reolinkActiveTypeFilter !== 'all') {
    var cat = activePane.getAttribute('data-cmd-cat')
    var st = reolinkCategoryStats[cat]
    if (st && st.all > 0 && st[reolinkActiveTypeFilter] === 0) {
      reolinkActiveTypeFilter = 'all'
    }
  }
  document.querySelectorAll('.reolinkCmdTypeFilterBtn').forEach(function (btn) {
    var isMatch = (btn.getAttribute('data-filter-type') === reolinkActiveTypeFilter)
    btn.classList.toggle('btn-primary', isMatch)
    btn.classList.toggle('btn-default', !isMatch)
  })
  document.querySelectorAll('.in_reolinkCmdSearch').forEach(function (inp) {
    if (inp.value !== reolinkActiveSearch) {
      inp.value = reolinkActiveSearch
    }
  })
}

function reolinkApplyCmdFilters() {
  reolinkSyncFilterUi()
  var needle = reolinkNormSearchText(reolinkActiveSearch.trim())
  document.querySelectorAll('.reolinkCmdTable tbody tr.cmd').forEach(function (row) {
    var matchType = (reolinkActiveTypeFilter === 'all') || (row.getAttribute('data-cmd-type') === reolinkActiveTypeFilter)
    var matchText = true
    if (matchType && needle !== '') {
      var nameInp = row.querySelector('input[data-l1key="name"]')
      var nameVal = nameInp ? nameInp.value : ''
      var lidVal = row.getAttribute('data-logical-id') || ''
      matchText = reolinkNormSearchText(nameVal + ' ' + lidVal).indexOf(needle) !== -1
    }
    row.classList.toggle('reolinkCmdFilteredOut', !(matchType && matchText))
  })
}

function reolinkEnsureSortables() {
  if (typeof Sortable === 'undefined') return
  document.querySelectorAll('.reolinkCmdTable tbody').forEach(function (tbody) {
    if (tbody._reolinkSortable) return
    tbody._reolinkSortable = Sortable.create(tbody, {
      delay: 100,
      delayOnTouchOnly: true,
      touchStartThreshold: 20,
      draggable: 'tr.cmd',
      filter: 'a, input, textarea, label, select',
      preventOnFilter: false,
      direction: 'vertical',
      chosenClass: 'dragSelected',
      onEnd: function () {
        if (typeof jeeFrontEnd !== 'undefined') jeeFrontEnd.modifyWithoutSave = true
        modifyWithoutSave = true
      }
    })
  })
}

function reolinkFlushCmdQueue() {
  if (reolinkLoadingEqLogic || reolinkCmdQueue.length === 0) return
  var frags = {}
  for (var c = 0; c < reolinkCmdCategories.length; c++) {
    frags[reolinkCmdCategories[c]] = document.createDocumentFragment()
  }
  var prevMod = (typeof jeeFrontEnd !== 'undefined') ? jeeFrontEnd.modifyWithoutSave : false
  var items = reolinkCmdQueue
  reolinkCmdQueue = []
  for (var i = 0; i < items.length; i++) {
    var row = reolinkBuildCmdRow(items[i])
    var cat = row.getAttribute('data-cmd-cat') || 'system'
    if (!frags[cat]) frags[cat] = document.createDocumentFragment()
    frags[cat].appendChild(row)
  }
  for (var k = 0; k < reolinkCmdCategories.length; k++) {
    var catKey = reolinkCmdCategories[k]
    var tbody = document.querySelector('#table_cmd_' + catKey + ' tbody')
    if (tbody && frags[catKey].childNodes.length > 0) {
      tbody.appendChild(frags[catKey])
      reolinkBindLiveCmdStates(tbody)
      if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.initTooltips === 'function') {
        jeedomUtils.initTooltips(tbody)
      }
    }
  }
  reolinkEnsureSortables()
  reolinkUpdateCmdTabsAndCounts(null)
  reolinkApplyCmdFilters()
  if (typeof jeeFrontEnd !== 'undefined') jeeFrontEnd.modifyWithoutSave = prevMod
  modifyWithoutSave = prevMod
}

function addCmdToTable(_cmd) {
  if (reolinkLoadingEqLogic) {
    reolinkCmdQueue.push(_cmd)
    return
  }
  if (reolinkCmdQueue.length > 0) {
    reolinkFlushCmdQueue()
  }
  var finish = function () {
    var row = reolinkBuildCmdRow(_cmd)
    var cat = row.getAttribute('data-cmd-cat') || 'system'
    var tbody = document.querySelector('#table_cmd_' + cat + ' tbody')
    if (!tbody) return
    tbody.appendChild(row)
    reolinkEnsureSortables()
    reolinkBindLiveCmdStates(row)
    if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.initTooltips === 'function') {
      jeedomUtils.initTooltips(row)
    }
    reolinkUpdateCmdTabsAndCounts(null)
    reolinkApplyCmdFilters()
  }
  if (reolinkInfoOptionsHtml === '' && $('.eqLogicAttr[data-l1key=id]').value()) {
    jeedom.eqLogic.buildSelectCmd({
      id: $('.eqLogicAttr[data-l1key=id]').value(),
      filter: {type: 'info'},
      error: function () { finish() },
      success: function (result) {
        reolinkInfoOptionsHtml = result || ''
        finish()
      }
    })
    return
  }
  finish()
}

function reolinkApplyDefaultVisibilityPreset() {
  if (reolinkCmdQueue.length > 0) {
    reolinkFlushCmdQueue()
  }
  var cfg = reolinkCurrentEqCfg || {}
  var chVal = String((typeof cfg.defined_channel !== 'undefined')
    ? cfg.defined_channel
    : ($('.eqLogicAttr[data-l1key=configuration][data-l2key=defined_channel]').value() || '')).trim()
  var cnxVal = String((typeof cfg.cnxtype !== 'undefined')
    ? cfg.cnxtype
    : ($('.eqLogicAttr[data-l1key=configuration][data-l2key=cnxtype]').value() || '')).trim().toLowerCase()
  var isChannelOrBc = (chVal !== '') || (cnxVal === 'baichuan')

  var total = 0
  var visibleCount = 0
  document.querySelectorAll('.reolinkCmdTable tbody tr.cmd').forEach(function (row) {
    var cb = row.querySelector('input.cmdAttr[data-l1key="isVisible"]')
    if (!cb) return
    total++
    var lid = row.getAttribute('data-logical-id') || ''
    var meta = reolinkCmdCatalog(lid)
    var wantVisible = (lid === 'NewFirmwareState' && isChannelOrBc)
      ? false
      : (parseInt(meta.defaultVisible || 0, 10) === 1)
    cb.checked = wantVisible
    if (wantVisible) visibleCount++
  })

  if (typeof jeeFrontEnd !== 'undefined') jeeFrontEnd.modifyWithoutSave = true
  modifyWithoutSave = true
  $('#div_alert').showAlert({
    message: '{{Affichage par défaut appliqué}} : ' + visibleCount + ' / ' + total + ' {{commandes cochées. Vérifiez au besoin puis cliquez sur Sauvegarder.}}',
    level: 'info'
  })
}

$('body').on('click', '.reolinkCmdTable .cmdHelpBtn', function (e) {
  e.preventDefault()
  e.stopPropagation()
  var row = this.closest('tr')
  if (row && row._reolinkHelp) {
    reolinkShowCmdHelp(row._reolinkHelp)
  }
})

$('body').on('click', '.reolinkCmdTypeFilterBtn', function (e) {
  e.preventDefault()
  reolinkActiveTypeFilter = String(this.getAttribute('data-filter-type') || 'all')
  reolinkApplyCmdFilters()
})

$('body').on('input keyup', '.in_reolinkCmdSearch', function () {
  reolinkActiveSearch = String(this.value || '')
  reolinkApplyCmdFilters()
})

$('body').on('click', '.bt_reolinkResetCmdSearch', function (e) {
  e.preventDefault()
  reolinkActiveSearch = ''
  reolinkApplyCmdFilters()
})

$('body').on('click', '.bt_reolinkResetDefaultVisible', function (e) {
  e.preventDefault()
  var confirmMsg = '{{Réinitialiser la case « Afficher » de toutes les commandes de cet équipement selon la sélection par défaut (commandes courantes) ? Les autres réglages (noms, icônes, historisation, inversions) seront conservés.}}'
  if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.confirm === 'function') {
    jeeDialog.confirm(confirmMsg, function (result) {
      if (result) reolinkApplyDefaultVisibilityPreset()
    })
    return
  }
  if (typeof bootbox !== 'undefined' && typeof bootbox.confirm === 'function') {
    bootbox.confirm(confirmMsg, function (result) {
      if (result) reolinkApplyDefaultVisibilityPreset()
    })
    return
  }
  if (window.confirm(confirmMsg)) {
    reolinkApplyDefaultVisibilityPreset()
  }
})

$('body').on('click', '.reolinkCmdTable .cmdAction[data-action="remove"]', function () {
  setTimeout(function () {
    reolinkUpdateCmdTabsAndCounts(null)
    reolinkApplyCmdFilters()
  }, 0)
})

$('body').on('change', '.reolinkCmdTable select.cmdAttr[data-l1key="type"]', function () {
  var row = this.closest('tr.cmd')
  if (row) {
    row.setAttribute('data-cmd-type', (this.value === 'action') ? 'action' : 'info')
    reolinkUpdateCmdTabsAndCounts(null)
    reolinkApplyCmdFilters()
  }
})

$('body').on('shown.bs.tab click', '.reolinkEqTabs a.reolinkCmdTabLink', function () {
  if (!reolinkLoadingEqLogic) {
    if (reolinkCmdQueue.length > 0) {
      requestAnimationFrame(reolinkFlushCmdQueue)
    } else {
      requestAnimationFrame(reolinkApplyCmdFilters)
    }
  }
})
