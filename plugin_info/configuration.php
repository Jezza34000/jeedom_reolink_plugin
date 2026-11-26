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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
$reolinkWebrtcEnabled = intval(config::byKey('enable_webrtc', 'reolink', 0)) === 1;
$reolinkInitDaemonCfg = array(
  'enable_webrtc' => $reolinkWebrtcEnabled ? '1' : '0',
  'go2rtc_api_port' => strval(config::byKey('go2rtc_api_port', 'reolink', '')),
  'go2rtc_webrtc_port' => strval(config::byKey('go2rtc_webrtc_port', 'reolink', '')),
  'go2rtc_webrtc_candidates' => strval(config::byKey('go2rtc_webrtc_candidates', 'reolink', '')),
  'ipwebhook' => strval(config::byKey('ipwebhook', 'reolink', '0')),
  'webhookdefinedip' => strval(config::byKey('webhookdefinedip', 'reolink', '')),
  'webhookport' => strval(config::byKey('webhookport', 'reolink', '44010')),
);
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Méthode d'authentification GET}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Méthode par laquelle le plugin envoie les commandes API (ne modifiez ce paramètre que si vous savez ce que vous faites)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="authmethod">
            <option value="0">Token</option>
            <option value="1">Login & Pass</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Taille des blocs de commandes}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Taille des blocs de commandes envoyés simultanément à la caméra (plus grand = plus rapide mais plus de risque de plantage ; plus petit = plus lent mais plus stable)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="cmdblock">
            <option value="2">2</option>
            <option value="4">4</option>
            <option value="8">8</option>
            <option value="16">16</option>
            <option value="24">24</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{IP de callback du webhook}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse IP vers laquelle les caméras renvoient les événements de détection de mouvement (IP permettant d'atteindre le webhook du démon du plugin)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="ipwebhook">
            <option value="0">Auto-détection</option>
            <option value="1">Interne</option>
            <option value="2">Externe</option>
            <option value="3">Personnalisée</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{IP personnalisée}}
        <sup><i class="fas fa-question-circle tooltips" title="{{IP utilisée pour le rappel du démon lorsque vous sélectionnez l'option Personnalisée}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="webhookdefinedip" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port du webhook}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port du webhook du démon utilisé par les caméras lors de la détection des mouvements}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="webhookport" value="44010" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port API local go2rtc}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port HTTP local (127.0.0.1) de l'instance go2rtc interne au plugin Reolink (44011 par défaut pour éviter tout conflit avec JeedomConnect sur 1984)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="go2rtc_api_port" placeholder="44011" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Live WebRTC & Microphone (interphonie)}}
        <sup><a href="#" class="reolinkWebrtcHelpTrigger tooltips" title="{{Cliquez pour afficher le guide complet (fMP4 vs WebRTC, ouverture réseau LAN / Internet et sécurité du port ICE)}}" style="color:var(--al-info-color);"><i class="fas fa-question-circle" style="color:var(--al-info-color);"></i></a></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="enable_webrtc" id="sel_reolinkEnableWebrtc">
          <option value="0">{{Désactivé — Flux vidéo fMP4 uniquement (par défaut)}}</option>
          <option value="1">{{Activé — WebRTC basse latence & bouton Parler (micro)}}</option>
        </select>
      </div>
      <div class="col-md-4">
        <a class="btn btn-default btn-sm reolinkWebrtcHelpTrigger" style="margin-top:2px;"><i class="fas fa-question-circle" style="color:var(--al-info-color);"></i> {{Aide WebRTC, accès distant & sécurité}}</a>
      </div>
    </div>
    <div class="form-group" id="fg_reolinkWebrtcPort" style="<?php echo $reolinkWebrtcEnabled ? '' : 'display:none;'; ?>">
      <label class="col-md-4 control-label">{{Port WebRTC go2rtc}}
        <sup><a href="#" class="reolinkWebrtcHelpTrigger tooltips" title="{{Port ICE TCP/UDP écouté par go2rtc uniquement lorsque WebRTC est activé (44012 par défaut pour éviter tout conflit avec JeedomConnect sur 8555). Aucun port réseau n'est ouvert tant que WebRTC est désactivé. À autoriser dans le pare-feu local de la box Jeedom.}}" style="color:var(--al-info-color);"><i class="fas fa-question-circle" style="color:var(--al-info-color);"></i></a></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="go2rtc_webrtc_port" placeholder="44012" />
      </div>
    </div>
    <div class="form-group" id="fg_reolinkWebrtcCandidates" style="<?php echo $reolinkWebrtcEnabled ? '' : 'display:none;'; ?>">
      <label class="col-md-4 control-label">{{Candidats ICE externes (accès distant, facultatif)}}
        <sup><a href="#" class="reolinkWebrtcHelpTrigger tooltips" title="{{Laissez vide pour un usage en réseau local (LAN / VPN). Pour utiliser WebRTC depuis l'extérieur : redirigez le port WebRTC (TCP et UDP) de votre routeur/box vers Jeedom, puis indiquez votre domaine ou IP publique suivi du port (ex : jeedom.mondomaine.fr:44012) ou stun:44012 (plusieurs entrées possibles séparées par des virgules).}}" style="color:var(--al-info-color);"><i class="fas fa-question-circle" style="color:var(--al-info-color);"></i></a></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="go2rtc_webrtc_candidates" placeholder="{{ex : jeedom.mondomaine.fr:44012 ou stun:44012}}" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Vérifier les mises à jour firmware (dépôt GitHub)}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Compare quotidiennement le modèle et la révision matérielle (hardVer) de vos caméras et NVR avec l'index AT0myks/reolink-fw-archive en ne retenant que les liens HTTPS hébergés sur les serveurs officiels Reolink. Fonctionne sans exposer les caméras à Internet et sans réveiller les caméras sur batterie.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="check_fw_github">
          <option value="1">{{Activé (liens officiels Reolink uniquement)}}</option>
          <option value="0">{{Désactivé}}</option>
        </select>
      </div>
    </div>
  </fieldset>
</form>
<div id="tpl_reolinkWebrtcHelp" style="display:none;">
  <div style="text-align:left;line-height:1.55;max-height:68vh;overflow-y:auto;padding-right:6px;">
    <p style="margin-bottom:6px;"><strong>1. {{fMP4 vs WebRTC — À quoi ça sert ?}}</strong></p>
    <ul style="margin-bottom:12px;padding-left:20px;">
      <li><strong>{{Flux fMP4 (toujours actif)}}</strong> : {{transmet la vidéo et le son entrant directement dans la connexion HTTP/HTTPS de Jeedom (~1 à 2 s de tampon). Fonctionne immédiatement partout, y compris à travers tout reverse proxy HTTPS (Apache, Nginx, DNS Jeedom, Cloudflare) sans aucun port supplémentaire à ouvrir.}}</li>
      <li><strong>{{WebRTC (optionnel)}}</strong> : {{offre une latence temps réel (< 0,5 s, idéale pour piloter un PTZ) et débloque le bouton Parler (microphone du navigateur vers le haut-parleur de la caméra). Les messages vocaux pré-enregistrés des sonnettes (bouton Répondre) fonctionnent même lorsque WebRTC est désactivé.}}</li>
      <li><strong>{{Accès au microphone}}</strong> : {{les navigateurs web exigent que Jeedom soit ouvert en HTTPS (ou sur localhost) pour autoriser la capture du microphone.}}</li>
    </ul>

    <p style="margin-bottom:6px;"><strong>2. {{Usage en réseau local (LAN / VPN) uniquement}}</strong></p>
    <ul style="margin-bottom:12px;padding-left:20px;">
      <li>{{Passez l'option sur Activé et laissez le champ Candidats ICE externes vide.}}</li>
      <li>{{Si un pare-feu actif filtre les entrées sur la box Jeedom (nftables / iptables / ufw), autorisez le Port WebRTC go2rtc (44012 TCP et UDP par défaut) pour votre sous-réseau local / VPN.}}</li>
    </ul>

    <p style="margin-bottom:6px;"><strong>3. {{Exposer WebRTC en public (accès distant hors VPN) — Quoi configurer ?}}</strong></p>
    <p style="margin-bottom:6px;">{{Contrairement au fMP4, un reverse proxy HTTP/HTTPS (Apache, Nginx) ne peut pas relayer les flux média WebRTC (ICE UDP/TCP) : il n'y a rien à modifier dans votre VirtualHost HTTP/HTTPS (la signalisation SDP passe déjà en HTTPS). Pour un accès direct hors VPN :}}</p>
    <ol style="margin-bottom:12px;padding-left:20px;">
      <li><strong>{{Routeur / Pare-feu Internet (NAT)}}</strong> : {{redirigez (DNAT) le port 44012 en TCP et en UDP de votre IP publique directement vers l'IP locale de la box Jeedom (et non vers une machine reverse proxy intermédiaire).}}</li>
      <li><strong>{{Pare-feu de la box Jeedom}}</strong> : {{autorisez le port 44012 en TCP et en UDP en entrée.}}</li>
      <li><strong>{{Candidats ICE externes}}</strong> : {{renseignez votre nom de domaine ou IP publique fixe suivi du port (ex : jeedom.mondomaine.fr:44012), ou stun:44012 si votre IP publique est dynamique (plusieurs valeurs possibles séparées par des virgules).}}</li>
    </ol>

    <p style="margin-bottom:6px;"><strong>4. {{Le port WebRTC (44012) est-il sécurisé s'il est exposé ?}}</strong></p>
    <ul style="margin-bottom:12px;padding-left:20px;">
      <li><strong>{{Aucune API ni flux accessible en direct}}</strong> : {{l'API de contrôle de go2rtc (127.0.0.1:44011) et le pont audio (127.0.0.1:44013) restent strictement locaux à la box Jeedom. Le port 44012 n'expose qu'un multiplexeur ICE qui ignore silencieusement tout paquet réseau non sollicité (scans, requêtes HTTP/RTSP).}}</li>
      <li><strong>{{Authentification Jeedom obligatoire en amont}}</strong> : {{une session ICE ne peut être créée qu'après authentification HTTPS valide sur Jeedom (session utilisateur / 2FA ou jeton révocable live_token).}}</li>
      <li><strong>{{Secrets éphémères & chiffrement DTLS-SRTP}}</strong> : {{chaque lecture génère des identifiants aléatoires à usage unique (ice-ufrag / ice-pwd > 128 bits vérifiés par signature HMAC-SHA1), puis chiffre intégralement la vidéo et le microphone de bout en bout en DTLS 1.2 / SRTP (AES-GCM) avec vérification mutuelle d'empreinte SHA-256. Connaître l'IP et le port 44012 ne permet ni de voir une caméra ni d'envoyer du son.}}</li>
      <li><strong>{{Surface minimale}}</strong> : {{tant que l'option est sur Désactivé, go2rtc n'ouvre aucun port 44012. Si vos accès distants passent déjà par un VPN, limiter l'ouverture pare-feu à votre sous-réseau LAN/VPN reste la meilleure pratique pour éviter le bruit de scans UDP sur Internet.}}</li>
    </ul>

    <p style="margin-bottom:6px;"><strong>5. {{Compatibilité avec JeedomConnect}}</strong></p>
    <p style="margin-bottom:0;">{{Laisser cette option Désactivée n'empêche pas d'utiliser le WebRTC ni le microphone dans l'application mobile JeedomConnect : celle-ci dispose de son propre moteur WebRTC/TURN et continue d'utiliser en arrière-plan le pont audio local du plugin (127.0.0.1:44013, champ RTSP Talk local sur la fiche équipement).}}</p>
  </div>
</div>
<script>
(function () {
  var savedDaemonCfg = <?php echo json_encode($reolinkInitDaemonCfg); ?>;
  var daemonKeys = ['enable_webrtc', 'go2rtc_api_port', 'go2rtc_webrtc_port', 'go2rtc_webrtc_candidates', 'ipwebhook', 'webhookdefinedip', 'webhookport'];

  function showWebrtcHelpModal(e) {
    if (e) { e.preventDefault(); }
    var tpl = document.getElementById('tpl_reolinkWebrtcHelp');
    var html = tpl ? tpl.innerHTML : '';
    var title = '{{Guide Live WebRTC, accès distant & sécurité}}';
    if (typeof jeeDialog !== 'undefined' && typeof jeeDialog.alert === 'function') {
      jeeDialog.alert({title: title, message: html, width: '68vw'});
      return;
    }
    if (typeof bootbox !== 'undefined' && typeof bootbox.alert === 'function') {
      bootbox.alert({title: title, size: 'large', message: html});
    }
  }

  document.querySelectorAll('#div_plugin_configuration .reolinkWebrtcHelpTrigger, .reolinkWebrtcHelpTrigger').forEach(function (btn) {
    if (btn._rlkHelpBound) { return; }
    btn._rlkHelpBound = true;
    btn.addEventListener('click', showWebrtcHelpModal);
  });

  function syncWebrtcFields() {
    var sel = document.getElementById('sel_reolinkEnableWebrtc');
    if (!sel) { return; }
    if (sel.value === '') { sel.value = '0'; }
    var on = (String(sel.value) === '1');
    var p = document.getElementById('fg_reolinkWebrtcPort');
    var c = document.getElementById('fg_reolinkWebrtcCandidates');
    if (p) { p.style.display = on ? '' : 'none'; }
    if (c) { c.style.display = on ? '' : 'none'; }
  }

  var selEl = document.getElementById('sel_reolinkEnableWebrtc');
  if (selEl) {
    selEl.addEventListener('change', syncWebrtcFields);
  }
  setTimeout(syncWebrtcFields, 150);
  setTimeout(syncWebrtcFields, 500);

  window.reolink_postSaveConfiguration = function () {
    syncWebrtcFields();
    var root = document.getElementById('div_plugin_configuration');
    if (!root || typeof root.getJeeValues !== 'function') { return; }
    var cur = (root.getJeeValues('.configKey') || [])[0] || {};
    var changed = daemonKeys.some(function (k) {
      return String(cur[k] == null ? '' : cur[k]) !== String(savedDaemonCfg[k] == null ? '' : savedDaemonCfg[k]);
    });
    if (!changed) { return; }
    daemonKeys.forEach(function (k) {
      savedDaemonCfg[k] = String(cur[k] == null ? '' : cur[k]);
    });
    if (typeof jeedom !== 'undefined' && jeedom.plugin && typeof jeedom.plugin.getDeamonInfo === 'function') {
      jeedom.plugin.getDeamonInfo({
        id: 'reolink',
        error: function () {},
        success: function (info) {
          if (info && info.state === 'ok') {
            jeedom.plugin.deamonStart({id: 'reolink', forceRestart: 1, error: function () {}, success: function () {}});
          }
        }
      });
    }
  };
})();
</script>
