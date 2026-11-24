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
