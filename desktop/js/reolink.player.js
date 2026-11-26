/* This file is part of Jeedom.
 * Shared Reolink Live / Snapshot / Two-Way Audio / PTZ player
 * Used by modal.live.php, Dashboard/Design tiles, and standalone core/php/live.php
 */
(function (window, document) {
  'use strict';

  if (window.ReolinkLivePlayer) {
    return;
  }

  function postForm(url, params) {
    var body = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k] == null ? '' : String(params[k]));
    }).join('&');
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body
    }).then(function (r) { return r.json(); });
  }

  function ReolinkLivePlayer(opts) {
    this.opts = opts || {};
    this.root = (typeof opts.root === 'string') ? document.querySelector(opts.root) : opts.root;
    if (!this.root) {
      return;
    }
    if (this.root._reolinkPlayer && typeof this.root._reolinkPlayer.destroy === 'function') {
      this.root._reolinkPlayer.destroy();
    }
    this.root._reolinkPlayer = this;

    this.eqId = String(opts.eqId || '');
    this.token = String(opts.token || '');
    this.basePath = String(opts.basePath || 'plugins/reolink/');
    this.streams = opts.streams || {};
    this.quality = String(opts.initialQuality || Object.keys(this.streams)[0] || 'sub');
    this.mode = String(opts.mode || 'live'); // 'live' | 'snapshot_live' | 'live_auto'
    this.refreshSec = Math.max(5, parseInt(opts.refreshSec || 15, 10) || 15);
    this.hasWebrtc = !!opts.hasWebrtc;
    this.isBattery = !!opts.isBattery;
    this.controls = opts.controls || {};
    this.i18n = opts.i18n || {};

    this.videoEl = this.root.querySelector('.rlkVideo');
    this.jpegEl = this.root.querySelector('.rlkJpeg');
    this.infoEl = this.root.querySelector('.rlkInfo');
    this.errEl = this.root.querySelector('.rlkErr');
    this.overlayEl = this.root.querySelector('.rlkPlayOverlay');
    this.stopBtn = this.root.querySelector('.rlkStopBtn');

    this.pc = null;
    this.micStream = null;
    this.talkActive = false;
    this.liveActive = false;
    this.inViewport = true;
    this.playGen = 0;
    this.playTimer = null;
    this.rtcTimer = null;
    this.jpegTimer = null;
    this.snapTimer = null;
    this.ptzTimer = null;
    this.observer = null;

    var zc = this.controls.zoom || {};
    var fc = this.controls.focus || {};
    this.zoomVal = parseInt(zc.cur || 0, 10) || 0;
    this.focusVal = parseInt(fc.cur || 0, 10) || 0;
    this.whiteOn = !!(this.controls.whiteLed && this.controls.whiteLed.state);

    var tokQs = this.token ? ('&token=' + encodeURIComponent(this.token)) : '';
    this.streamBase = this.basePath + 'core/php/stream.php?id=' + encodeURIComponent(this.eqId) + tokQs + '&quality=';
    this.snapBase = this.basePath + 'core/php/snap.php?id=' + encodeURIComponent(this.eqId) + tokQs;
    this.ajaxUrl = this.basePath + 'core/ajax/reolink.ajax.php';
    this.liveExecUrl = this.basePath + 'core/php/live.php';

    this._bindUi();
    this._bindLifecycle();
    this._startInitialMode();
  }

  ReolinkLivePlayer.prototype._t = function (key, fallback) {
    return (this.i18n && this.i18n[key]) ? this.i18n[key] : fallback;
  };

  ReolinkLivePlayer.prototype._alive = function () {
    return !!(this.root && document.body && document.body.contains(this.root));
  };

  ReolinkLivePlayer.prototype.showErr = function (msg) {
    if (!this.errEl) { return; }
    if (!msg) {
      this.errEl.style.display = 'none';
      this.errEl.textContent = '';
      return;
    }
    this.errEl.textContent = msg;
    this.errEl.style.display = 'block';
  };

  ReolinkLivePlayer.prototype._setInfo = function (txt) {
    if (this.infoEl) {
      this.infoEl.textContent = txt;
    }
  };

  ReolinkLivePlayer.prototype._updateTalkUi = function () {
    var self = this;
    this.root.querySelectorAll('.rlkTalkBtn').forEach(function (btn) {
      btn.classList.toggle('btn-danger', self.talkActive);
      btn.classList.toggle('active', self.talkActive);
      btn.classList.toggle('btn-default', !self.talkActive);
      var ic = btn.querySelector('i');
      if (ic) {
        ic.className = self.talkActive ? 'fas fa-microphone' : 'fas fa-microphone-slash';
      }
    });
  };

  ReolinkLivePlayer.prototype._updateLiveStateUi = function () {
    if (this.overlayEl) {
      this.overlayEl.style.display = (!this.liveActive && this.mode === 'snapshot_live') ? 'flex' : 'none';
    }
    if (this.stopBtn) {
      this.stopBtn.style.display = (this.liveActive && this.mode === 'snapshot_live') ? '' : 'none';
    }
  };

  ReolinkLivePlayer.prototype._stopMic = function () {
    if (this.micStream) {
      this.micStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      this.micStream = null;
    }
    this.talkActive = false;
    this._updateTalkUi();
  };

  ReolinkLivePlayer.prototype._stopJpeg = function () {
    if (this.jpegTimer) { clearTimeout(this.jpegTimer); this.jpegTimer = null; }
  };

  ReolinkLivePlayer.prototype._stopSnapLoop = function () {
    if (this.snapTimer) { clearTimeout(this.snapTimer); this.snapTimer = null; }
  };

  ReolinkLivePlayer.prototype._stopRtc = function () {
    if (this.rtcTimer) { clearTimeout(this.rtcTimer); this.rtcTimer = null; }
    if (this.pc) { try { this.pc.close(); } catch (e) {} this.pc = null; }
    if (this.videoEl) { this.videoEl.srcObject = null; }
  };

  ReolinkLivePlayer.prototype._stopMse = function () {
    if (!this.videoEl) { return; }
    try { this.videoEl.pause(); } catch (e) {}
    try {
      this.videoEl.removeAttribute('src');
      this.videoEl.src = '';
      this.videoEl.load();
    } catch (e) {}
  };

  ReolinkLivePlayer.prototype.stopLive = function (keepJpegFrame) {
    this.playGen++;
    this.liveActive = false;
    if (this.playTimer) { clearTimeout(this.playTimer); this.playTimer = null; }
    this._stopMic();
    this._stopJpeg();
    this._stopSnapLoop();
    this._stopRtc();
    this._stopMse();
    if (!keepJpegFrame && this.jpegEl) {
      this.jpegEl.removeAttribute('src');
    }
    this.showErr('');
    this._updateLiveStateUi();
  };

  ReolinkLivePlayer.prototype.startSnapshotLoop = function () {
    var self = this;
    this.stopLive(true);
    if (this.videoEl) { this.videoEl.style.display = 'none'; }
    if (this.jpegEl) { this.jpegEl.style.display = ''; }
    this._setInfo('JPEG');
    var gen = this.playGen;

    function tick() {
      if (gen !== self.playGen || !self._alive()) { return; }
      if (!self.inViewport || document.hidden) {
        self.snapTimer = setTimeout(tick, 2000);
        return;
      }
      var url = self.snapBase + '&quality=sub' + (self.isBattery ? '&no_wake=1' : '') + '&_=' + Date.now();
      var probe = new Image();
      probe.onload = function () {
        if (gen !== self.playGen || !self._alive()) { return; }
        if (self.jpegEl && probe.naturalWidth > 0) {
          self.jpegEl.src = url;
        }
        self.snapTimer = setTimeout(tick, self.refreshSec * 1000);
      };
      probe.onerror = function () {
        if (gen !== self.playGen || !self._alive()) { return; }
        self.snapTimer = setTimeout(tick, self.refreshSec * 1000);
      };
      probe.src = url;
    }
    tick();
  };

  ReolinkLivePlayer.prototype.playJpeg = function () {
    var self = this;
    this.stopLive(false);
    this.liveActive = true;
    this._updateLiveStateUi();
    if (this.videoEl) { this.videoEl.style.display = 'none'; }
    if (this.jpegEl) { this.jpegEl.style.display = ''; }
    var gen = this.playGen;
    this._setInfo('jpeg / cmd 109');

    function tick() {
      if (gen !== self.playGen || !self._alive() || !self.jpegEl) { return; }
      self.jpegEl.onload = function () {
        if (gen === self.playGen) { self.jpegTimer = setTimeout(tick, 900); }
      };
      self.jpegEl.onerror = function () {
        if (gen === self.playGen) {
          self.showErr(self._t('jpegErr', 'Échec de capture JPEG'));
          self.jpegTimer = setTimeout(tick, 2500);
        }
      };
      self.jpegEl.src = self.snapBase + '&quality=sub&_=' + Date.now();
    }
    tick();
  };

  ReolinkLivePlayer.prototype.playMse = function (q) {
    var self = this;
    q = q || this.quality;
    if (this.streams[q] && this.streams[q].type === 'jpeg') {
      this.playJpeg();
      return;
    }
    this._stopMic();
    this._stopJpeg();
    this._stopSnapLoop();
    this._stopRtc();
    this._stopMse();
    this.liveActive = true;
    this.showErr('');
    this._updateLiveStateUi();
    if (this.jpegEl) { this.jpegEl.style.display = 'none'; }
    if (this.videoEl) { this.videoEl.style.display = ''; }
    var gen = ++this.playGen;
    this.playTimer = setTimeout(function () {
      if (gen !== self.playGen || !self._alive() || !self.videoEl) { return; }
      self.videoEl.src = self.streamBase + encodeURIComponent(q) + '&_=' + Date.now();
      self.videoEl.play().catch(function () {});
      self._setInfo(q + ' / fMP4');
    }, 80);
  };

  ReolinkLivePlayer.prototype.playWebRtc = function (q, withMic) {
    var self = this;
    q = q || this.quality;
    if (this.streams[q] && this.streams[q].type === 'jpeg') {
      this.playJpeg();
      return;
    }
    if (!this.hasWebrtc || !window.RTCPeerConnection) {
      this.playMse(q);
      return;
    }
    this.stopLive(false);
    this.liveActive = true;
    this._updateLiveStateUi();
    if (this.jpegEl) { this.jpegEl.style.display = 'none'; }
    if (this.videoEl) { this.videoEl.style.display = ''; }
    var gen = this.playGen;
    var gotTrack = false;
    var fallbackDone = false;
    var triggerFallback = function (errKey, defaultMsg) {
      if (gotTrack || fallbackDone || gen !== self.playGen || !self._alive()) { return; }
      fallbackDone = true;
      self.playMse(q);
      self.showErr(self._t(errKey, defaultMsg));
    };

    var micPromise = Promise.resolve(null);
    if (withMic) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.showErr(this._t('micHttps', 'Le microphone nécessite un accès HTTPS (ou localhost) dans le navigateur.'));
        withMic = false;
      } else {
        micPromise = navigator.mediaDevices.getUserMedia({
          audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true}
        });
      }
    }

    micPromise.then(function (mStream) {
      if (gen !== self.playGen || !self._alive()) {
        if (mStream) { mStream.getTracks().forEach(function (t) { t.stop(); }); }
        return;
      }
      if (mStream) {
        self.micStream = mStream;
        self.talkActive = true;
        if (self.videoEl) { self.videoEl.muted = false; }
      }
      self._updateTalkUi();
      self._setInfo(q + (self.talkActive ? ' / WebRTC + Micro…' : ' / WebRTC…'));

      var pc = new RTCPeerConnection({iceServers: []});
      self.pc = pc;
      if (self.micStream && self.micStream.getAudioTracks().length > 0) {
        pc.addTransceiver(self.micStream.getAudioTracks()[0], {direction: 'sendonly'});
      }
      pc.addTransceiver('video', {direction: 'recvonly'});
      pc.addTransceiver('audio', {direction: 'recvonly'});

      var inboundStream = new MediaStream();
      pc.ontrack = function (ev) {
        if (gen !== self.playGen || !self._alive() || !self.videoEl) { return; }
        gotTrack = true;
        if (self.rtcTimer) { clearTimeout(self.rtcTimer); self.rtcTimer = null; }
        if (ev.track) {
          inboundStream.addTrack(ev.track);
          self.videoEl.srcObject = inboundStream;
        } else if (ev.streams && ev.streams[0]) {
          self.videoEl.srcObject = ev.streams[0];
        }
        self.videoEl.play().catch(function () {});
        self._setInfo(q + (self.talkActive ? ' / WebRTC + Micro' : ' / WebRTC'));
        self.showErr('');
      };
      pc.oniceconnectionstatechange = function () {
        var st = pc ? pc.iceConnectionState : '';
        if (st === 'failed') {
          triggerFallback('rtcFallback', 'WebRTC indisponible, bascule fMP4');
        }
      };
      self.rtcTimer = setTimeout(function () {
        triggerFallback('rtcTimeout', 'WebRTC timeout, bascule fMP4');
      }, 4500);

      return pc.createOffer().then(function (offer) {
        return pc.setLocalDescription(offer);
      }).then(function () {
        return new Promise(function (resolve) {
          if (pc.iceGatheringState === 'complete') { resolve(); return; }
          var t = setTimeout(resolve, 800);
          pc.onicegatheringstatechange = function () {
            if (pc.iceGatheringState === 'complete') { clearTimeout(t); resolve(); }
          };
        });
      }).then(function () {
        if (gen !== self.playGen) { return; }
        return postForm(self.ajaxUrl, {
          action: 'webrtcOffer',
          id: self.eqId,
          token: self.token,
          quality: q,
          sdp: pc.localDescription.sdp
        });
      }).then(function (res) {
        if (!self.pc || gen !== self.playGen) { return; }
        if (!res || res.state !== 'ok' || !res.result || !res.result.sdp) { throw new Error('no sdp'); }
        return pc.setRemoteDescription({type: 'answer', sdp: res.result.sdp});
      });
    }).catch(function (err) {
      var isPerm = err && (err.name === 'NotAllowedError' || err.name === 'NotFoundError');
      triggerFallback(
        isPerm ? 'micDenied' : 'rtcFallback',
        isPerm ? 'Accès au microphone refusé ou aucun microphone détecté' : 'WebRTC indisponible, bascule fMP4'
      );
    });
  };

  ReolinkLivePlayer.prototype.setQuality = function (q) {
    if (!q || !this.streams[q]) { return; }
    this.quality = q;
    this.root.querySelectorAll('.rlkQualityBtn').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-quality') === q);
    });
    this.root.querySelectorAll('.rlkQualitySel').forEach(function (sel) {
      if (sel.value !== q) { sel.value = q; }
    });
    if (this.talkActive) {
      this.playWebRtc(q, true);
    } else {
      this.playMse(q);
    }
  };

  ReolinkLivePlayer.prototype.execCmd = function (cmdId, valueObj) {
    if (!cmdId) { return; }
    if (!this.token && typeof window.jeedom !== 'undefined' && window.jeedom.cmd && typeof window.jeedom.cmd.execute === 'function') {
      window.jeedom.cmd.execute({id: cmdId, value: valueObj || {}});
      return;
    }
    var params = {action: 'execCmd', id: this.eqId, token: this.token, cmdId: cmdId};
    if (valueObj && typeof valueObj.select !== 'undefined') { params.select = valueObj.select; }
    if (valueObj && typeof valueObj.slider !== 'undefined') { params.slider = valueObj.slider; }
    postForm(this.liveExecUrl, params).catch(function () {});
  };

  ReolinkLivePlayer.prototype._bindUi = function () {
    var self = this;
    if (this.videoEl) {
      this.videoEl.onerror = function () {
        if (!self.videoEl || !self.videoEl.getAttribute('src')) { return; }
        self.showErr((self.quality === 'main' || self.quality === 'autotrack_main')
          ? self._t('h265Err', 'Lecture impossible (codec High H.265 non supporté par ce navigateur ? Essayez Low ou Fluent).')
          : self._t('mseErr', 'Erreur de lecture du flux fMP4'));
      };
    }

    this.root.querySelectorAll('.rlkQualityBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        self.setQuality(String(btn.getAttribute('data-quality') || 'sub'));
      });
    });
    this.root.querySelectorAll('.rlkQualitySel').forEach(function (sel) {
      sel.addEventListener('change', function () {
        if (self.liveActive || self.mode !== 'snapshot_live') {
          self.setQuality(String(sel.value || 'sub'));
        } else {
          self.quality = String(sel.value || 'sub');
        }
      });
    });

    if (this.overlayEl) {
      this.overlayEl.addEventListener('click', function (e) {
        e.preventDefault();
        self.setQuality(self.quality);
      });
    }
    if (this.stopBtn) {
      this.stopBtn.addEventListener('click', function (e) {
        e.preventDefault();
        self.startSnapshotLoop();
      });
    }

    this.root.querySelectorAll('.rlkWebRtcBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        self.playWebRtc(self.quality, false);
      });
    });
    this.root.querySelectorAll('.rlkTalkBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        self.playWebRtc(self.quality, !self.talkActive);
      });
    });

    this.root.querySelectorAll('.rlkSnapBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var snapQ = (self.quality === 'main' || self.quality === 'autotrack_main') ? 'main' : 'sub';
        window.open(self.snapBase + '&quality=' + snapQ, '_blank');
      });
    });

    this.root.querySelectorAll('.rlkFsBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var target = (self.videoEl && self.videoEl.style.display !== 'none') ? self.videoEl : self.root;
        if (document.fullscreenElement) {
          document.exitFullscreen().catch(function () {});
        } else if (target && target.requestFullscreen) {
          target.requestFullscreen().catch(function () {});
        } else if (self.videoEl && self.videoEl.webkitEnterFullscreen) {
          self.videoEl.webkitEnterFullscreen();
        }
      });
    });

    this.root.querySelectorAll('.rlkOpenModalBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (self.mode === 'snapshot_live' && self.liveActive) {
          self.startSnapshotLoop();
        }
        if (typeof window.$ !== 'undefined' && window.$('#md_modal').length) {
          window.$('#md_modal').dialog({title: btn.getAttribute('data-title') || 'Live Reolink'});
          window.$('#md_modal').load('index.php?v=d&plugin=reolink&modal=modal.live&id=' + encodeURIComponent(self.eqId)).dialog('open');
        }
      });
    });

    var ptzCfg = this.controls.ptz || {};
    if (ptzCfg.cmdId) {
      this.root.querySelectorAll('.rlkPtzBtn').forEach(function (btn) {
        var holding = false;
        var startMove = function (e) {
          e.preventDefault();
          var dir = btn.getAttribute('data-dir');
          if (!dir) { return; }
          holding = true;
          if (self.ptzTimer) { clearTimeout(self.ptzTimer); self.ptzTimer = null; }
          self.execCmd(ptzCfg.cmdId, {select: dir});
          self.ptzTimer = setTimeout(function () {
            if (holding) { return; }
            self.execCmd(ptzCfg.cmdId, {select: 'Stop'});
          }, 420);
        };
        var stopMove = function () {
          if (!holding) { return; }
          holding = false;
          if (self.ptzTimer) { clearTimeout(self.ptzTimer); self.ptzTimer = null; }
          self.execCmd(ptzCfg.cmdId, {select: 'Stop'});
        };
        btn.addEventListener('mousedown', startMove);
        btn.addEventListener('mouseup', stopMove);
        btn.addEventListener('mouseleave', stopMove);
        btn.addEventListener('touchstart', startMove, {passive: false});
        btn.addEventListener('touchend', stopMove);
      });
    }

    var zCfg = this.controls.zoom || {};
    if (zCfg.cmdId) {
      this.root.querySelectorAll('.rlkZoomBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var step = parseInt(btn.getAttribute('data-step') || '0', 10) || 0;
          var minV = parseInt(zCfg.min || 0, 10) || 0;
          var maxV = parseInt(zCfg.max || 32, 10) || 32;
          self.zoomVal = Math.max(minV, Math.min(maxV, self.zoomVal + step));
          if (self._zoomTimer) {
            clearTimeout(self._zoomTimer);
          }
          self._zoomTimer = setTimeout(function () {
            self._zoomTimer = null;
            self.execCmd(zCfg.cmdId, {slider: self.zoomVal});
          }, 180);
        });
      });
    }

    var fCfg = this.controls.focus || {};
    if (fCfg.cmdId) {
      this.root.querySelectorAll('.rlkFocusBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var step = parseInt(btn.getAttribute('data-step') || '0', 10) || 0;
          var minV = parseInt(fCfg.min || 0, 10) || 0;
          var maxV = parseInt(fCfg.max || 255, 10) || 255;
          self.focusVal = Math.max(minV, Math.min(maxV, self.focusVal + step));
          if (self._focusTimer) {
            clearTimeout(self._focusTimer);
          }
          self._focusTimer = setTimeout(function () {
            self._focusTimer = null;
            self.execCmd(fCfg.cmdId, {slider: self.focusVal});
          }, 180);
        });
      });
    }

    var prCfg = this.controls.presets || {};
    var prSel = this.root.querySelector('.rlkPresetSel');
    if (prCfg.cmdId && prSel) {
      var triggerPreset = function () {
        if (prSel.value !== '') {
          self.execCmd(prCfg.cmdId, {select: prSel.value});
        }
      };
      this.root.querySelectorAll('.rlkPresetGoBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) { e.preventDefault(); triggerPreset(); });
      });
      prSel.addEventListener('change', function () {
        if (prSel.getAttribute('data-auto-go') === '1') { triggerPreset(); }
      });
    }
    this.root.querySelectorAll('.rlkPresetSaveBtn').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var curSlot = (prSel && prSel.value !== '') ? prSel.value : '1';
        var curLabel = (prSel && prSel.selectedOptions && prSel.selectedOptions[0]) ? prSel.selectedOptions[0].textContent : '';
        var slotStr = window.prompt(self._t('presetSlotPrompt', 'Numéro du preset PTZ à enregistrer (0..63) :'), curSlot);
        if (slotStr === null || slotStr.trim() === '') { return; }
        var slotId = Math.max(0, Math.min(63, parseInt(slotStr, 10) || 0));
        var defName = curLabel && curLabel.indexOf('(') === -1 ? curLabel : ('Preset ' + slotId);
        var pName = window.prompt(self._t('presetNamePrompt', 'Nom du preset PTZ :'), defName);
        if (pName === null || pName.trim() === '') { return; }
        var targetUrl = self.token ? self.liveExecUrl : self.ajaxUrl;
        postForm(targetUrl, {
          action: 'SavePtzPreset',
          id: self.eqId,
          token: self.token,
          presetId: slotId,
          name: pName.trim()
        }).then(function (res) {
          if (!res || res.state !== 'ok') {
            self.showErr((res && res.result) ? String(res.result) : 'SavePtzPreset error');
            return;
          }
          if (prSel && res.result && Array.isArray(res.result.presets)) {
            prSel.innerHTML = '';
            res.result.presets.forEach(function (it) {
              var o = document.createElement('option');
              o.value = String(it.id);
              o.textContent = String(it.label);
              if (String(it.id) === String(slotId)) { o.selected = true; }
              prSel.appendChild(o);
            });
          }
        }).catch(function () {});
      });
    });

    var patCfg = this.controls.patrols || {};
    var patSel = this.root.querySelector('.rlkPatrolSel');
    if (patCfg.startCmdId) {
      this.root.querySelectorAll('.rlkPatrolStartBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var val = patSel ? patSel.value : '0';
          self.execCmd(patCfg.startCmdId, {select: val});
        });
      });
    }
    if (patCfg.stopCmdId) {
      this.root.querySelectorAll('.rlkPatrolStopBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          self.execCmd(patCfg.stopCmdId, {});
        });
      });
    }

    var qrCfg = this.controls.quickReplies || {};
    var qrSel = this.root.querySelector('.rlkQuickReplySel');
    if (qrCfg.cmdId && qrSel) {
      this.root.querySelectorAll('.rlkQuickReplyBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          if (qrSel.value !== '') {
            self.execCmd(qrCfg.cmdId, {select: qrSel.value});
          }
        });
      });
    }

    var wCfg = this.controls.whiteLed || {};
    if (wCfg.cmdId) {
      this.root.querySelectorAll('.rlkWhiteBtn').forEach(function (btn) {
        btn.classList.toggle('btn-warning', self.whiteOn);
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          self.whiteOn = !self.whiteOn;
          btn.classList.toggle('btn-warning', self.whiteOn);
          self.execCmd(wCfg.cmdId, {select: self.whiteOn ? 1 : 0});
        });
      });
    }

    var sCfg = this.controls.siren || {};
    if (sCfg.cmdId) {
      this.root.querySelectorAll('.rlkSirenBtn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          self.execCmd(sCfg.cmdId, {select: 1});
        });
      });
    }
  };

  ReolinkLivePlayer.prototype._bindLifecycle = function () {
    var self = this;
    this._onVisChange = function () {
      if (!self._alive()) {
        self.destroy();
        return;
      }
      if (document.hidden) {
        if (self.mode === 'live_auto') {
          self.stopLive(true);
        }
      } else if (self.mode === 'live_auto' && self.inViewport && !self.liveActive) {
        self.setQuality(self.quality);
      }
    };
    this._onUnload = function () {
      self.stopLive(false);
    };
    document.addEventListener('visibilitychange', this._onVisChange);
    window.addEventListener('beforeunload', this._onUnload);
    window.addEventListener('pagehide', this._onUnload);

    if ((this.mode === 'snapshot_live' || this.mode === 'live_auto') && typeof window.IntersectionObserver === 'function') {
      this.observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          self.inViewport = !!entry.isIntersecting;
          if (!self.inViewport) {
            if (self.mode === 'live_auto' && self.liveActive) {
              self.stopLive(true);
            } else if (self.mode === 'snapshot_live' && self.liveActive) {
              self.startSnapshotLoop();
            }
          } else if (self.mode === 'live_auto' && !document.hidden && !self.liveActive) {
            self.setQuality(self.quality);
          }
        });
      }, {threshold: 0.05});
      this.observer.observe(this.root);
    }
  };

  ReolinkLivePlayer.prototype._startInitialMode = function () {
    if (this.mode === 'snapshot_live') {
      this.startSnapshotLoop();
    } else {
      this.setQuality(this.quality);
    }
  };

  ReolinkLivePlayer.prototype.destroy = function () {
    if (this._zoomTimer) {
      clearTimeout(this._zoomTimer);
      this._zoomTimer = null;
    }
    if (this._focusTimer) {
      clearTimeout(this._focusTimer);
      this._focusTimer = null;
    }
    this.stopLive(false);
    if (this.observer) {
      try { this.observer.disconnect(); } catch (e) {}
      this.observer = null;
    }
    if (this._onVisChange) {
      document.removeEventListener('visibilitychange', this._onVisChange);
    }
    if (this._onUnload) {
      window.removeEventListener('beforeunload', this._onUnload);
      window.removeEventListener('pagehide', this._onUnload);
    }
  };

  window.ReolinkLivePlayer = ReolinkLivePlayer;
})(window, document);
